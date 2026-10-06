<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use Symfony\Component\Clock\ClockInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Effective course access from entitlement sources.
 *
 * A learner sees one enrolment per course (the library, the reader and learning progress use it);
 * behind it are any number of entitlement sources: an individual purchase, each bundle that includes
 * the course, free access, and the enrolment's own non-commerce origin once a commerce source has
 * joined it. Each source keeps its own access period, activation and expiry; no source is changed
 * because another exists. The course is accessible while any source is valid (awaiting activation or
 * active), and the enrolment's expires_at is the latest expiry among the active sources.
 *
 * Activation, per source: a source starts when the learner starts the course, or at the moment the
 * source was granted if the learner had already started, or automatically at its own activation
 * deadline, whichever comes first. An origin source has no deadline: like the enrolment it came
 * from, it starts when the learner starts. Expiry, per source: a source ends when its own period has
 * run; the course stays open while another source is valid.
 *
 * An enrolment with no sources is legacy access (ADMIN, company, seed) and keeps its existing path.
 */
final class AccessService
{
    private const VALID = ['awaiting_activation', 'active'];

    public function __construct(private readonly CommerceRepository $records, private readonly TransactionManager $transactions, private readonly TransitionService $transitions, private readonly ClockInterface $clock) {}

    /**
     * Applies each source's activation and expiry as of now, under row locks (also when the scheduled
     * worker was delayed), projects the effective expiry onto the enrolment, and returns the effective
     * access: state (active, awaiting_activation, or the reason none is valid), access_expires_at and
     * the sources. Null for an enrolment with no sources.
     *
     * @return array<string,mixed>|null
     */
    public function reconcile(int $enrolmentId, bool $start = false): ?array
    {
        return $this->transactions->run(function() use($enrolmentId,$start): ?array {
            $enrolment=$this->records->accessEnrolment($enrolmentId,true);
            if ($enrolment===null) return null;
            $sources=$this->records->sources($enrolmentId,true);
            if ($sources===[]) return null;
            $now=$this->clock->now();
            $started=$enrolment['started_at']===null ? null : new DateTimeImmutable((string)$enrolment['started_at']);
            // Starting now counts as the learner's start for activation; it is recorded below only if access is then open.
            $startsAt=$started ?? ($start ? $now : null);
            foreach ($sources as &$source) {
                if ($source['state']==='awaiting_activation') {
                    $deadline=$source['activation_deadline_at']===null ? null : new DateTimeImmutable((string)$source['activation_deadline_at']);
                    $fromStart=$startsAt===null ? null : max(new DateTimeImmutable((string)$source['created_at']),$startsAt);
                    $automatic=$deadline!==null && $deadline<=$now && ($fromStart===null || $deadline<$fromStart);
                    $begins=$automatic ? $deadline : $fromStart;
                    if ($begins!==null && $begins<=$now) {
                        $state=$this->transitions->apply('entitlement','awaiting_activation',$automatic?'auto_activate':'activate');
                        $expires=$begins->modify('+'.(int)$source['access_period_seconds'].' seconds');
                        $this->records->activateSource((int)$source['id'],$state,$begins->format(DATE_ATOM),$expires->format(DATE_ATOM));
                        $this->records->audit(null,$start?(int)$enrolment['user_id']:null,'entitlement.activated',['enrolment_id'=>$enrolmentId,'entitlement_id'=>(int)$source['id'],'source'=>$source['source'],'automatic'=>$automatic,'access_started_at'=>$begins->format(DATE_ATOM)],$now->format(DATE_ATOM));
                        $source['state']=$state;
                        $source['access_started_at']=$begins->format(DATE_ATOM);
                        $source['access_expires_at']=$expires->format(DATE_ATOM);
                    }
                }
                if ($source['state']==='active' && new DateTimeImmutable((string)$source['access_expires_at'])<=$now) {
                    $this->transitions->apply('entitlement','active','expire');
                    $this->records->expireSource((int)$source['id']);
                    $this->records->audit(null,null,'entitlement.expired',['enrolment_id'=>$enrolmentId,'entitlement_id'=>(int)$source['id'],'source'=>$source['source']],$now->format(DATE_ATOM));
                    $source['state']='expired';
                }
            }
            unset($source);
            $effective=self::effective($sources);
            if ($start && $started===null && $effective['state']==='active') {
                $this->records->learnerStarted($enrolmentId,$now->format(DATE_ATOM));
                $this->records->audit(null,(int)$enrolment['user_id'],'entitlement.learner_started',['enrolment_id'=>$enrolmentId],$now->format(DATE_ATOM));
                $started=$now;
            }
            if ($effective['state']==='active' || $effective['state']==='awaiting_activation') {
                $this->records->projectAccess($enrolmentId,$effective['access_expires_at']);
            } elseif ($effective['last_expiry']!==null) {
                $this->records->projectAccess($enrolmentId,$effective['last_expiry']);
            }
            return $effective+['enrolment_id'=>$enrolmentId,'user_id'=>(int)$enrolment['user_id'],'course_id'=>(int)$enrolment['course_id'],
                'learner_started_at'=>$started?->format(DATE_ATOM),'sources'=>$sources];
        });
    }

    /** Returns false for legacy access, true while any source is valid; denied access never erases history. */
    public function assertAccess(int $enrolmentId): bool
    {
        $e=$this->reconcile($enrolmentId);
        if ($e===null) return false;
        if (!in_array($e['state'],self::VALID,true)) throw new InvalidArgumentException('Your course access is '.$e['state'].'.');
        return true;
    }

    public function start(int $enrolmentId): bool
    {
        $e=$this->reconcile($enrolmentId,true);
        if ($e===null) return false;
        if ($e['state']!=='active') throw new InvalidArgumentException('Your course access is '.$e['state'].'.');
        return true;
    }

    /**
     * Revokes these sources and nothing else, then works out each affected course's access again
     * from the sources that remain. A course no source keeps open is closed; one another source
     * still covers stays open, now until that source's own expiry.
     *
     * @param list<int> $sourceIds
     * @return array<int,bool> for each affected enrolment, whether it is still accessible
     */
    public function revoke(array $sourceIds, string $reason): array
    {
        return $this->transactions->run(function() use($sourceIds,$reason): array {
            $now=$this->clock->now()->format(DATE_ATOM);
            $affected=[];
            foreach ($sourceIds as $sourceId) {
                $source=$this->records->source($sourceId,true);
                if ($source===null) continue;
                if ($this->records->revokeSource($sourceId)) {
                    $this->records->audit(null,null,'entitlement.revoked',['enrolment_id'=>(int)$source['enrolment_id'],'entitlement_id'=>$sourceId,'source'=>$source['source'],'reason'=>$reason],$now);
                }
                $affected[(int)$source['enrolment_id']]=true;
            }
            $result=[];
            foreach (array_keys($affected) as $enrolmentId) {
                $access=$this->reconcile($enrolmentId);
                $open=$access!==null && in_array($access['state'],self::VALID,true);
                if (!$open) $this->records->cancelEnrolment($enrolmentId,$now);
                $result[$enrolmentId]=$open;
            }
            return $result;
        });
    }

    /**
     * The effective access of a set of sources: active while any is active, until the latest active
     * expiry; awaiting activation while none is active but one awaits; otherwise the reason none is
     * valid (revoked when every source was revoked, else expired, else suspended).
     *
     * @param list<array<string,mixed>> $sources
     * @return array{state:string,access_expires_at:?string,last_expiry:?string}
     */
    public static function effective(array $sources): array
    {
        $states=array_column($sources,'state');
        $latest=static function (array $rows): ?string {
            $times=array_filter(array_map(static fn(array $s): ?int => $s['access_expires_at']===null ? null : (new DateTimeImmutable((string)$s['access_expires_at']))->getTimestamp(),$rows));
            return $times===[] ? null : (new DateTimeImmutable('@'.max($times)))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
        };
        $active=array_values(array_filter($sources,static fn(array $s): bool => $s['state']==='active'));
        if ($active!==[]) return ['state'=>'active','access_expires_at'=>$latest($active),'last_expiry'=>$latest($active)];
        if (in_array('awaiting_activation',$states,true)) return ['state'=>'awaiting_activation','access_expires_at'=>null,'last_expiry'=>null];
        $state=count(array_filter($states,static fn(string $s): bool => $s!=='revoked'))===0 ? 'revoked' : (in_array('expired',$states,true) ? 'expired' : 'suspended');
        $ended=array_values(array_filter($sources,static fn(array $s): bool => $s['state']==='expired'));
        return ['state'=>$state,'access_expires_at'=>null,'last_expiry'=>$latest($ended)];
    }
}
