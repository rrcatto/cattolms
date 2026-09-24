<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Infrastructure\Persistence\AdministrationRepository;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/** Settles paid company order lines into traceable credit lots and fulfils a linked request atomically. */
final class CompanyCreditFulfilment
{
    public function __construct(
        private readonly CommerceRepository $records,
        private readonly AdministrationRepository $administration,
        private readonly CourseRepository $courses,
        private readonly ClockInterface $clock
    ) {}

    /**
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $items
     */
    public function ready(array $order, array $items): bool
    {
        if ($order['company_id'] === null || $order['request_id'] === null) return true;
        $request = $this->administration->requestForUpdate((int)$order['request_id']);
        if ($request === null || $request['status'] !== 'pending' || (int)$request['company_id'] !== (int)$order['company_id']
            || !$this->administration->isActiveCompanyMember((int)$order['company_id'], (int)$request['user_id'])
            || $this->records->hasOpenEnrolment((int)$request['user_id'], (int)$request['course_id'])) return false;
        foreach ($items as $item) {
            if ($item['product_type'] === 'company_credit' && (int)$item['course_id'] === (int)$request['course_id']
                && (int)$item['access_period_seconds'] === (int)$request['access_period_seconds']) return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $items
     */
    public function fulfil(array $order, array $items): void
    {
        if ($order['company_id'] === null) throw new RuntimeException('This is not a company purchase.');
        $companyId = (int)$order['company_id'];
        $now = $this->clock->now()->format(DATE_ATOM);
        $creditByItem = [];
        foreach ($items as $item) {
            if ($item['product_type'] !== 'company_credit' || (int)$item['company_id'] !== $companyId) {
                throw new RuntimeException('A company order item has the wrong beneficiary.');
            }
            $creditByItem[(int)$item['id']] = $this->records->createPurchasedCredit(
                (int)$item['id'],$companyId,(int)$item['course_id'],(int)$item['access_period_seconds'],
                (int)$item['quantity'],(int)$order['purchaser_user_id'],$now
            );
            $this->records->audit((int)$order['id'],null,'company_credit.created',
                ['order_item_id'=>$item['id'],'credit_id'=>$creditByItem[(int)$item['id']],'quantity'=>$item['quantity']],$now);
        }
        if ($order['request_id'] === null) return;
        $request = $this->administration->requestForUpdate((int)$order['request_id']);
        if ($request === null || $request['status'] !== 'pending') throw new RuntimeException('The linked request changed during settlement.');
        foreach ($items as $item) {
            if ((int)$item['course_id'] !== (int)$request['course_id']
                || (int)$item['access_period_seconds'] !== (int)$request['access_period_seconds']) continue;
            $enrolment = $this->courses->grantCourse((int)$request['user_id'],(int)$request['course_id'],
                (int)$request['access_period_seconds'],(int)$order['purchaser_user_id']);
            $this->administration->allocateCredit($creditByItem[(int)$item['id']],(int)$request['user_id'],$enrolment,(int)$order['purchaser_user_id']);
            $this->administration->decideRequest((int)$request['id'],'fulfilled','Approved after company credit purchase.',(int)$order['purchaser_user_id'],$enrolment);
            $this->records->audit((int)$order['id'],null,'company_request.fulfilled',
                ['request_id'=>$request['id'],'credit_id'=>$creditByItem[(int)$item['id']],'enrolment_id'=>$enrolment],$now);
            $this->records->enqueue('company-request-approved:'.$request['id'],'company.request_decision',
                ['email'=>$request['email'],'course_title'=>$request['course_title']],$now);
            $this->records->enqueue('company-request-enrolled:'.$request['id'],'company.request_enrolment',
                ['email'=>$request['email'],'course_title'=>$request['course_title'],'slug'=>$request['slug']],$now);
            return;
        }
        throw new RuntimeException('No purchased credit matches the linked request.');
    }
}
