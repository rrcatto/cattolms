<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use Symfony\Component\Clock\ClockInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/** Separates purchased access clocks from learner progress; legacy enrolments keep their existing path. */
final class AccessService
{
    public function __construct(private readonly CommerceRepository $records, private readonly TransactionManager $transactions, private readonly TransitionService $transitions, private readonly ClockInterface $clock) {}

    /**
     * Reconciles deadlines under row locks, including when the scheduled worker was delayed.
     * @return array<string,mixed>|null
     */
    public function reconcile(int $enrolmentId, bool $start = false): ?array
    {
        return $this->transactions->run(function() use($enrolmentId,$start): ?array {
            $e=$this->records->entitlement($enrolmentId,true);
            if ($e===null) return null;
            $now=$this->clock->now();
            $deadline=new DateTimeImmutable((string)$e['activation_deadline_at']);
            if ($e['state']==='awaiting_activation' && ($start || $deadline<=$now)) {
                $begins=$deadline<=$now?$deadline:$now;
                $state=$this->transitions->apply('entitlement',(string)$e['state'],$deadline<=$now?'auto_activate':'activate');
                $expires=$begins->modify('+'.(int)$e['access_period_seconds'].' seconds');
                $this->records->activate($enrolmentId,$state,$begins->format(DATE_ATOM),$expires->format(DATE_ATOM));
                $this->records->audit(null,$start?(int)$e['user_id']:null,'entitlement.activated',['enrolment_id'=>$enrolmentId,'automatic'=>$deadline<=$now,'access_started_at'=>$begins->format(DATE_ATOM)],$now->format(DATE_ATOM));
                $e=$this->records->entitlement($enrolmentId) ?? $e;
            }
            if ($e['state']==='active' && new DateTimeImmutable((string)$e['access_expires_at'])<=$now) {
                $this->transitions->apply('entitlement','active','expire');
                $this->records->expireEntitlement($enrolmentId);
                $this->records->audit(null,null,'entitlement.expired',['enrolment_id'=>$enrolmentId],$now->format(DATE_ATOM));
                $e['state']='expired';
            }
            if ($start && $e['state']==='active' && empty($e['learner_started_at'])) {
                $this->records->learnerStarted($enrolmentId,$now->format(DATE_ATOM));
                $this->records->audit(null,(int)$e['user_id'],'entitlement.learner_started',['enrolment_id'=>$enrolmentId],$now->format(DATE_ATOM));
            }
            return $e;
        });
    }

    /** Returns false for legacy access, true for a managed entitlement; denied access never erases history. */
    public function assertAccess(int $enrolmentId): bool
    {
        $e=$this->reconcile($enrolmentId);
        if ($e===null) return false;
        if (!in_array($e['state'],['awaiting_activation','active'],true)) throw new InvalidArgumentException('Your course access is '.$e['state'].'.');
        return true;
    }
    public function start(int $enrolmentId): bool
    {
        $e=$this->reconcile($enrolmentId,true);
        if ($e===null) return false;
        if ($e['state']!=='active') throw new InvalidArgumentException('Your course access is '.$e['state'].'.');
        return true;
    }
}
