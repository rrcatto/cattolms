<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Infrastructure;

use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Uuid;
use RuntimeException;

/** Commerce SQL on the LMS connection; callers own transactions and policy decisions. */
final class CommerceRepository
{
    public function __construct(private readonly Database $db) {}

    /** Serializes each purchaser's checkout and fulfilment decisions. */
    public function lockPurchaser(int $userId): void
    {
        if (!$this->db->fetchOne("SELECT id FROM users WHERE id=:id AND status='active' FOR UPDATE", ['id'=>$userId])) {
            throw new RuntimeException('The purchasing account is unavailable.');
        }
    }

    /** @return array<string,mixed> */
    public function cart(int $userId): array
    {
        $this->db->executeStatement("INSERT INTO commerce_carts(user_id) VALUES (:user) ON CONFLICT (user_id) WHERE state='open' DO NOTHING", ['user'=>$userId]);
        return $this->db->fetchAssociative("SELECT * FROM commerce_carts WHERE user_id=:user AND state='open'", ['user'=>$userId]) ?: throw new RuntimeException('Cart unavailable.');
    }

    /** @return list<array<string,mixed>> */
    public function cartItems(int $cartId): array
    {
        return $this->db->fetchAllAssociative('SELECT v.*,c.title,c.slug,c.status AS course_status FROM commerce_cart_items i JOIN course_price_variants v ON v.id=i.variant_id JOIN courses c ON c.id=v.course_id WHERE i.cart_id=:id ORDER BY v.id', ['id'=>$cartId]);
    }

    /** @return array<string,mixed> */
    public function offer(int $variantId): array
    {
        return $this->db->fetchAssociative('SELECT v.*,c.title,c.slug,c.status AS course_status,c.owner_company_id FROM course_price_variants v JOIN courses c ON c.id=v.course_id WHERE v.id=:id', ['id'=>$variantId]) ?: throw new RuntimeException('The offer does not exist.');
    }

    /** @return list<array<string,mixed>> */
    public function companyOffers(int $companyId, string $search = '', int $limit = 30): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT v.*,c.title,c.slug,c.status AS course_status,c.owner_company_id FROM course_price_variants v
             JOIN courses c ON c.id=v.course_id
             WHERE v.is_active=TRUE AND c.status='published' AND v.price_minor_units>0 AND v.currency_code='ZAR'
               AND (c.owner_company_id IS NULL OR c.owner_company_id<>:company)
               AND (:search='' OR c.title ILIKE :pattern)
             ORDER BY c.title,v.access_period_seconds,v.id LIMIT :limit",
            ['company'=>$companyId,'search'=>$search,'pattern'=>'%'.str_replace(['%','_'],['\\%','\\_'],$search).'%','limit'=>$limit]
        );
    }

    /** @return array<string,mixed>|null */
    public function companyOfferForRequest(int $requestId, int $companyId): ?array
    {
        return $this->db->fetchAssociative(
            "SELECT cr.id AS request_id,cr.status AS request_status,cr.user_id,cr.course_id,
                    cr.access_period_seconds,v.id AS variant_id,v.price_minor_units,v.currency_code,
                    c.title,c.slug,c.owner_company_id
             FROM course_requests cr JOIN courses c ON c.id=cr.course_id
             JOIN course_price_variants v ON v.course_id=c.id AND v.access_period_seconds=cr.access_period_seconds
             WHERE cr.id=:request AND cr.company_id=:company AND cr.status='pending'
               AND c.status='published' AND v.is_active=TRUE AND v.price_minor_units>0 AND v.currency_code='ZAR'
               AND (c.owner_company_id IS NULL OR c.owner_company_id<>:company)
             ORDER BY v.is_default DESC,v.id LIMIT 1",
            ['request'=>$requestId,'company'=>$companyId]
        ) ?: null;
    }

    public function changeCart(int $cartId, int $variantId, bool $remove): void
    {
        if ($remove) {
            $this->db->executeStatement('DELETE FROM commerce_cart_items WHERE cart_id=:cart AND variant_id=:variant', ['cart'=>$cartId,'variant'=>$variantId]);
        } else {
            $this->db->executeStatement('INSERT INTO commerce_cart_items(cart_id,variant_id) VALUES (:cart,:variant) ON CONFLICT DO NOTHING', ['cart'=>$cartId,'variant'=>$variantId]);
        }
        $this->db->executeStatement('UPDATE commerce_carts SET revision=revision+1 WHERE id=:id', ['id'=>$cartId]);
    }

    /** @return array<string,mixed>|null */
    public function orderForCart(int $cartId, int $userId): ?array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_orders WHERE cart_id=:cart AND purchaser_user_id=:user', ['cart'=>$cartId,'user'=>$userId]) ?: null;
    }

    /** @return array<string,mixed>|null */
    public function companyOrderForKey(int $userId, string $key): ?array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_orders WHERE purchaser_user_id=:user AND company_purchase_key=:key', ['user'=>$userId,'key'=>$key]) ?: null;
    }

    /** @return array<string,mixed>|null */
    public function activeCompanyOrderForRequest(int $requestId, int $companyId): ?array
    {
        return $this->db->fetchAssociative(
            "SELECT id,state,purchaser_user_id FROM commerce_orders WHERE request_id=:request AND company_id=:company
             AND state IN ('placed','awaiting_payment','manual_review','paid','fulfilled') ORDER BY id DESC LIMIT 1",
            ['request'=>$requestId,'company'=>$companyId]
        ) ?: null;
    }

    /** @param array<string,mixed> $snapshot */
    public function placeCompany(int $userId, int $companyId, ?int $requestId, string $key, int $total, string $currency, array $snapshot, string $now, string $due): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO commerce_orders(public_id,purchaser_user_id,company_id,request_id,company_purchase_key,state,total_minor,currency,snapshot,placed_at,payment_due_at)
             VALUES (:public,:user,:company,:request,:key,'placed',:total,:currency,:snapshot,:now,:due) RETURNING id",
            ['public'=>Uuid::v4(),'user'=>$userId,'company'=>$companyId,'request'=>$requestId,'key'=>$key,'total'=>$total,'currency'=>$currency,'snapshot'=>self::json($snapshot),'now'=>$now,'due'=>$due]
        );
    }

    /** @param array<string,mixed> $snapshot */
    public function addCompanyOrderItem(int $orderId, int $companyId, array $snapshot): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO commerce_order_items(order_id,variant_id,course_id,beneficiary_user_id,company_id,quantity,product_type,amount_minor,access_period_seconds,snapshot)
             VALUES (:order,:variant,:course,NULL,:company,:quantity,'company_credit',:amount,:duration,:snapshot) RETURNING id",
            ['order'=>$orderId,'variant'=>$snapshot['variant_id'],'course'=>$snapshot['course_id'],'company'=>$companyId,
             'quantity'=>$snapshot['quantity'],'amount'=>$snapshot['line_total_minor'],'duration'=>$snapshot['access_period_seconds'],'snapshot'=>self::json($snapshot)]
        );
    }

    public function purchasedCredit(int $itemId): ?int
    {
        $id = $this->db->fetchOne('SELECT id FROM course_credits WHERE commerce_order_item_id=:item', ['item'=>$itemId]);
        return $id === false ? null : (int) $id;
    }

    public function createPurchasedCredit(int $itemId, int $companyId, int $courseId, int $period, int $quantity, int $actorId, string $now): int
    {
        $created = $this->db->fetchOne(
            "INSERT INTO course_credits(public_id,company_id,course_id,access_period_seconds,quantity,source_type,source_reference,commerce_order_item_id,created_by_user_id,created_at)
             VALUES (:public,:company,:course,:period,:quantity,'purchase',:reference,:item,:actor,:now)
             ON CONFLICT(commerce_order_item_id) DO NOTHING RETURNING id",
            ['public'=>Uuid::v4(),'company'=>$companyId,'course'=>$courseId,'period'=>$period,'quantity'=>$quantity,
             'reference'=>(string)$itemId,'item'=>$itemId,'actor'=>$actorId,'now'=>$now]
        );
        return $created === false ? ($this->purchasedCredit($itemId) ?? throw new RuntimeException('Purchased credit lot unavailable.')) : (int) $created;
    }

    /** @param array<string,mixed> $snapshot */
    public function place(int $cartId, int $userId, int $total, string $currency, array $snapshot, string $now, string $due): int
    {
        $id = (int) $this->db->fetchOne("INSERT INTO commerce_orders(public_id,purchaser_user_id,cart_id,state,total_minor,currency,snapshot,placed_at,payment_due_at) VALUES (:public,:user,:cart,'placed',:total,:currency,:snapshot,:now,:due) RETURNING id", ['public'=>Uuid::v4(),'user'=>$userId,'cart'=>$cartId,'total'=>$total,'currency'=>$currency,'snapshot'=>self::json($snapshot),'now'=>$now,'due'=>$due]);
        $this->db->executeStatement("UPDATE commerce_carts SET state='placed' WHERE id=:id", ['id'=>$cartId]);
        return $id;
    }

    /** @param array<string,mixed> $snapshot */
    public function addOrderItem(int $orderId, int $userId, array $snapshot): void
    {
        $this->db->executeStatement('INSERT INTO commerce_order_items(order_id,variant_id,course_id,beneficiary_user_id,amount_minor,access_period_seconds,snapshot) VALUES (:order,:variant,:course,:user,:amount,:duration,:snapshot)', ['order'=>$orderId,'variant'=>$snapshot['variant_id'],'course'=>$snapshot['course_id'],'user'=>$userId,'amount'=>$snapshot['unit_price_minor'],'duration'=>$snapshot['access_period_seconds'],'snapshot'=>self::json($snapshot)]);
    }

    /** @return array<string,mixed> */
    public function order(int $id, bool $lock = false): array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_orders WHERE id=:id' . ($lock ? ' FOR UPDATE' : ''), ['id'=>$id]) ?: throw new RuntimeException('Order not found.');
    }

    /** @return list<array<string,mixed>> */
    public function orders(int $userId, int $limit, int $offset): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM commerce_orders WHERE purchaser_user_id=:user ORDER BY id DESC LIMIT :limit OFFSET :offset', ['user'=>$userId,'limit'=>$limit,'offset'=>$offset]);
    }
    public function orderCount(int $userId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM commerce_orders WHERE purchaser_user_id=:user', ['user'=>$userId]);
    }
    public function setOrderState(int $id, string $state): void
    {
        $this->db->executeStatement('UPDATE commerce_orders SET state=:state WHERE id=:id', ['id'=>$id,'state'=>$state]);
    }
    /** @return list<array<string,mixed>> */
    public function items(int $orderId): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM commerce_order_items WHERE order_id=:id ORDER BY id', ['id'=>$orderId]);
    }
    /** @return list<array<string,mixed>> */
    public function documents(int $orderId): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM commerce_documents WHERE order_id=:id ORDER BY id', ['id'=>$orderId]);
    }
    /** @param array<string,mixed> $snapshot */
    public function document(int $orderId, string $kind, string $sourceKey, array $snapshot, string $now): void
    {
        if ($this->db->fetchOne('SELECT id FROM commerce_documents WHERE source_key=:key', ['key'=>$sourceKey])) return;
        $number = $this->db->fetchOne('UPDATE commerce_document_numbers SET next_number=next_number+1 WHERE kind=:kind RETURNING next_number-1', ['kind'=>$kind]);
        if ($number === false) throw new RuntimeException('Unknown financial document type.');
        $prefix = match ($kind) { 'invoice'=>'INV-', 'receipt'=>'REC-', 'credit_note'=>'CN-', default=>throw new RuntimeException('Unknown financial document type.') };
        $this->db->executeStatement('INSERT INTO commerce_documents(public_id,order_id,kind,number,source_key,snapshot,issued_at) VALUES (:public,:order,:kind,:number,:key,:snapshot,:now)', ['public'=>Uuid::v4(),'order'=>$orderId,'kind'=>$kind,'number'=>$prefix.str_pad((string)$number,8,'0',STR_PAD_LEFT),'key'=>$sourceKey,'snapshot'=>self::json($snapshot),'now'=>$now]);
    }
    /** @param array<string,mixed> $payload */
    public function audit(?int $orderId, ?int $actor, string $event, array $payload, string $now): void
    {
        $this->db->executeStatement('INSERT INTO commerce_audit_events(order_id,actor_user_id,event,payload,created_at) VALUES (:order,:actor,:event,:payload,:now)', ['order'=>$orderId,'actor'=>$actor,'event'=>$event,'payload'=>self::json($payload),'now'=>$now]);
    }
    /** @return array<string,mixed>|null */
    public function paymentForRequest(int $orderId, string $key): ?array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_payments WHERE order_id=:order AND request_key=:key', ['order'=>$orderId,'key'=>$key]) ?: null;
    }
    /** @return array<string,mixed> */
    public function payment(string $id): array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_payments WHERE id=:id', ['id'=>$id]) ?: throw new RuntimeException('Payment not found.');
    }
    /** @return list<array<string,mixed>> */
    public function payments(int $orderId): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM commerce_payments WHERE order_id=:id ORDER BY created_at,id', ['id'=>$orderId]);
    }
    /** @param array<string,mixed> $order */
    public function createPayment(array $order, string $key, string $gateway, string $now): string
    {
        $id=Uuid::v4();
        $this->db->executeStatement("INSERT INTO commerce_payments(id,order_id,request_key,gateway,state,amount_minor,currency,created_at) VALUES (:id,:order,:key,:gateway,'created',:amount,:currency,:now)", ['id'=>$id,'order'=>$order['id'],'key'=>$key,'gateway'=>$gateway,'amount'=>$order['total_minor'],'currency'=>$order['currency'],'now'=>$now]);
        return $id;
    }
    public function setPaymentState(string $id, string $state, ?string $reference): void
    {
        $this->db->executeStatement('UPDATE commerce_payments SET state=:state,provider_reference=COALESCE(:reference,provider_reference) WHERE id=:id', ['id'=>$id,'state'=>$state,'reference'=>$reference]);
    }
    /** @param array<string,mixed> $payload */
    public function paymentEvent(string $id, string $key, string $state, array $payload, string $now): bool
    {
        return $this->db->executeStatement('INSERT INTO commerce_payment_events(payment_id,event_key,state,payload,created_at) VALUES (:id,:key,:state,:payload,:now) ON CONFLICT(event_key) DO NOTHING', ['id'=>$id,'key'=>$key,'state'=>$state,'payload'=>self::json($payload),'now'=>$now]) === 1;
    }
    public function hasOpenEnrolment(int $userId, int $courseId): bool
    {
        return (bool)$this->db->fetchOne("SELECT id FROM course_enrolments WHERE user_id=:user AND course_id=:course AND NOT is_preview AND status IN ('assigned','active','completed')", ['user'=>$userId,'course'=>$courseId]);
    }
    public function hasPayableCourse(int $userId, int $courseId): bool
    {
        return (bool)$this->db->fetchOne("SELECT i.id FROM commerce_order_items i JOIN commerce_orders o ON o.id=i.order_id WHERE i.beneficiary_user_id=:user AND i.course_id=:course AND o.state IN ('placed','awaiting_payment','manual_review','paid')", ['user'=>$userId,'course'=>$courseId]);
    }
    /** @return array<string,mixed>|null */
    public function entitlement(int $enrolmentId, bool $lock = false): ?array
    {
        return $this->db->fetchAssociative('SELECT e.*,ce.user_id,ce.course_id,ce.access_period_seconds,ce.started_at AS learner_started_at,ce.status AS learning_status FROM commerce_entitlements e JOIN course_enrolments ce ON ce.id=e.enrolment_id WHERE e.enrolment_id=:id' . ($lock?' FOR UPDATE OF e,ce':''), ['id'=>$enrolmentId]) ?: null;
    }
    public function fulfilledItem(int $itemId): bool
    {
        return (bool)$this->db->fetchOne('SELECT id FROM commerce_entitlements WHERE order_item_id=:id', ['id'=>$itemId]);
    }
    /** @param array<string,mixed> $snapshot */
    public function createEntitlement(?int $itemId, int $userId, int $courseId, int $duration, string $source, array $snapshot, string $now, string $deadline): int
    {
        $enrolment = (int)$this->db->fetchOne("INSERT INTO course_enrolments(public_id,user_id,course_id,source_type,source_reference,status,access_period_seconds,assigned_at,created_at,updated_at) VALUES (:public,:user,:course,:source,:reference,'assigned',:duration,:now,:now,:now) RETURNING id", ['public'=>Uuid::v4(),'user'=>$userId,'course'=>$courseId,'source'=>$source,'reference'=>$itemId===null?null:(string)$itemId,'duration'=>$duration,'now'=>$now]);
        $this->db->executeStatement("INSERT INTO commerce_entitlements(order_item_id,enrolment_id,state,source,snapshot,created_at,activation_deadline_at) VALUES (:item,:enrolment,'awaiting_activation',:source,:snapshot,:now,:deadline)", ['item'=>$itemId,'enrolment'=>$enrolment,'source'=>$source,'snapshot'=>self::json($snapshot),'now'=>$now,'deadline'=>$deadline]);
        return $enrolment;
    }
    public function activate(int $enrolmentId, string $state, string $start, string $expiry): void
    {
        $this->db->executeStatement('UPDATE commerce_entitlements SET state=:state,access_started_at=:start,access_expires_at=:expiry WHERE enrolment_id=:id', ['id'=>$enrolmentId,'state'=>$state,'start'=>$start,'expiry'=>$expiry]);
        // expires_at remains a compatibility projection; started_at continues to mean learner commencement.
        $this->db->executeStatement('UPDATE course_enrolments SET expires_at=:expiry WHERE id=:id', ['id'=>$enrolmentId,'expiry'=>$expiry]);
    }
    public function learnerStarted(int $enrolmentId, string $now): void
    {
        $this->db->executeStatement("UPDATE course_enrolments SET started_at=COALESCE(started_at,:now),status=CASE WHEN status='assigned' THEN 'active' ELSE status END,updated_at=:now WHERE id=:id", ['id'=>$enrolmentId,'now'=>$now]);
    }
    public function expireEntitlement(int $enrolmentId): void
    {
        $this->db->executeStatement("UPDATE commerce_entitlements SET state='expired' WHERE enrolment_id=:id", ['id'=>$enrolmentId]);
    }
    /** @return list<int> */
    public function dueEntitlements(string $now, int $limit): array
    {
        return array_map('intval',$this->db->fetchFirstColumn("SELECT enrolment_id FROM commerce_entitlements WHERE (state='awaiting_activation' AND activation_deadline_at<=:now) OR (state='active' AND access_expires_at<=:now) ORDER BY id LIMIT :limit", ['now'=>$now,'limit'=>$limit]));
    }
    /** @return list<int> */
    public function dueOrders(string $now, int $limit): array
    {
        return array_map('intval',$this->db->fetchFirstColumn("SELECT id FROM commerce_orders WHERE state='awaiting_payment' AND payment_due_at<=:now ORDER BY id LIMIT :limit", ['now'=>$now,'limit'=>$limit]));
    }
    /** @param array<string,mixed> $payload */
    public function enqueue(string $key, string $event, array $payload, string $now): void
    {
        $this->db->executeStatement('INSERT INTO commerce_outbox(event_key,event,payload,created_at) VALUES (:key,:event,:payload,:now) ON CONFLICT(event_key) DO NOTHING', ['key'=>$key,'event'=>$event,'payload'=>self::json($payload),'now'=>$now]);
    }
    /** @return array<string,mixed>|null */
    public function nextInvoiceEmail(): ?array
    {
        return $this->db->fetchAssociative("SELECT * FROM commerce_outbox WHERE event='invoice.email_requested' AND delivered_at IS NULL AND (last_error IS NULL OR created_at + attempts * INTERVAL '5 minutes' < NOW()) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED") ?: null;
    }
    /** @return array<string,mixed>|null */
    public function nextCompanyCourseNotice(): ?array
    {
        return $this->db->fetchAssociative("SELECT * FROM commerce_outbox WHERE event IN ('company.request_decision','company.request_enrolment')
            AND delivered_at IS NULL AND (last_error IS NULL OR created_at + attempts * INTERVAL '5 minutes' < NOW())
            ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED") ?: null;
    }
    public function emailDelivered(int $id, ?string $error): void
    {
        $this->db->executeStatement('UPDATE commerce_outbox SET attempts=attempts+1,last_error=:error,delivered_at=CASE WHEN :ok THEN NOW() ELSE NULL END WHERE id=:id', ['id'=>$id,'error'=>$error,'ok'=>$error===null]);
    }

    public function selectPaymentMethod(int $orderId, string $method): void
    {
        $this->db->executeStatement('INSERT INTO commerce_order_preferences(order_id,payment_method) VALUES (:id,:method) ON CONFLICT(order_id) DO UPDATE SET payment_method=EXCLUDED.payment_method', ['id'=>$orderId,'method'=>$method]);
    }
    public function paymentMethod(int $orderId): string
    {
        return (string) ($this->db->fetchOne('SELECT payment_method FROM commerce_order_preferences WHERE order_id=:id', ['id'=>$orderId]) ?: 'dummy');
    }
    /** @return list<array<string,mixed>> */
    public function administrationOrders(string $search = '', int $limit = 100): array
    {
        return $this->db->fetchAllAssociative("SELECT o.id,o.state,o.total_minor,o.currency,o.placed_at,o.company_id,o.request_id,u.display_name AS purchaser_name,ue.email AS purchaser_email FROM commerce_orders o JOIN users u ON u.id=o.purchaser_user_id LEFT JOIN user_emails ue ON ue.user_id=u.id AND ue.is_primary=TRUE WHERE (:search='' OR o.id::text=:search OR ue.email ILIKE :pattern) ORDER BY o.id DESC LIMIT :limit", ['search'=>$search,'pattern'=>'%'.str_replace(['%','_'],['\\%','\\_'],$search).'%','limit'=>$limit]);
    }
    /** @return array<string,mixed>|null */
    public function manualEvidence(string $paymentId): ?array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_manual_payment_evidence WHERE payment_id=:id', ['id'=>$paymentId]) ?: null;
    }
    public function recordManualEvidence(string $paymentId, int $orderId, int $amount, string $currency, string $receivedAt, string $reference, string $reason, int $actorId, string $now): void
    {
        $this->db->executeStatement('INSERT INTO commerce_manual_payment_evidence(payment_id,order_id,amount_minor,currency,received_at,bank_reference,reason,actor_user_id,confirmed_at) VALUES (:payment,:order,:amount,:currency,:received,:reference,:reason,:actor,:now)', ['payment'=>$paymentId,'order'=>$orderId,'amount'=>$amount,'currency'=>$currency,'received'=>$receivedAt,'reference'=>$reference,'reason'=>$reason,'actor'=>$actorId,'now'=>$now]);
    }
    public function paidTotal(int $orderId): int
    {
        return (int)$this->db->fetchOne("SELECT COALESCE(SUM(amount_minor),0) FROM commerce_payments WHERE order_id=:id AND state IN ('paid','partially_refunded','refunded')", ['id'=>$orderId]);
    }
    /** @return list<array<string,mixed>> */
    public function refunds(int $orderId): array
    {
        return $this->db->fetchAllAssociative('SELECT r.*,u.display_name AS actor_name FROM commerce_refunds r JOIN users u ON u.id=r.approved_by_user_id WHERE r.order_id=:id ORDER BY r.id', ['id'=>$orderId]);
    }
    public function refundedAmount(int $orderId, ?int $itemId = null): int
    {
        return (int)$this->db->fetchOne('SELECT COALESCE(SUM(amount_minor),0) FROM commerce_refunds WHERE order_id=:order'.($itemId===null?'':' AND order_item_id=:item'),$itemId===null?['order'=>$orderId]:['order'=>$orderId,'item'=>$itemId]);
    }
    /** @return array<string,mixed>|null */
    public function refundByKey(string $key): ?array
    {
        return $this->db->fetchAssociative('SELECT id,order_id,order_item_id FROM commerce_refunds WHERE request_key=:key', ['key'=>$key]) ?: null;
    }
    /** @return array<string,mixed> */
    public function orderItem(int $itemId, int $orderId): array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_order_items WHERE id=:item AND order_id=:order', ['item'=>$itemId,'order'=>$orderId]) ?: throw new RuntimeException('This order item is unavailable.');
    }
    /** @return array<string,mixed>|null */
    public function latestRefundableCredit(int $companyId, int $courseId, int $period, bool $lock = false): ?array
    {
        return $this->db->fetchAssociative("SELECT cc.*,i.order_id,i.amount_minor,i.quantity AS purchased_quantity,cc.quantity-cc.refunded_quantity-(SELECT COUNT(*) FROM course_credit_allocations a WHERE a.credit_id=cc.id AND a.status IN ('assigned','consumed')) AS available_count FROM course_credits cc JOIN commerce_order_items i ON i.id=cc.commerce_order_item_id WHERE cc.company_id=:company AND cc.course_id=:course AND cc.access_period_seconds=:period AND cc.quantity-cc.refunded_quantity>(SELECT COUNT(*) FROM course_credit_allocations a WHERE a.credit_id=cc.id AND a.status IN ('assigned','consumed')) ORDER BY i.id DESC LIMIT 1".($lock?' FOR UPDATE OF cc':''), ['company'=>$companyId,'course'=>$courseId,'period'=>$period]) ?: null;
    }
    public function refundCreditUnits(int $creditId, int $quantity): void
    {
        $this->db->executeStatement('UPDATE course_credits SET refunded_quantity=refunded_quantity+:quantity WHERE id=:id', ['quantity'=>$quantity,'id'=>$creditId]);
    }
    public function creditAllocationCount(int $creditId): int
    {
        return (int)$this->db->fetchOne("SELECT COUNT(*) FROM course_credit_allocations WHERE credit_id=:id AND status IN ('assigned','consumed')", ['id'=>$creditId]);
    }
    public function createRefund(string $key, int $orderId, int $itemId, int $quantity, int $amount, string $currency, string $basis, string $reason, int $actorId, string $now): int
    {
        return (int)$this->db->fetchOne('INSERT INTO commerce_refunds(public_id,request_key,order_id,order_item_id,quantity,amount_minor,currency,basis,reason,approved_by_user_id,approved_at) VALUES (:public,:key,:order,:item,:quantity,:amount,:currency,:basis,:reason,:actor,:now) RETURNING id', ['public'=>Uuid::v4(),'key'=>$key,'order'=>$orderId,'item'=>$itemId,'quantity'=>$quantity,'amount'=>$amount,'currency'=>$currency,'basis'=>$basis,'reason'=>$reason,'actor'=>$actorId,'now'=>$now]);
    }
    public function creditRefundFunds(int $refundId, ?int $userId, ?int $companyId, string $currency, int $amount, string $now): void
    {
        $this->db->executeStatement('INSERT INTO commerce_fund_accounts(owner_user_id,owner_company_id,currency) VALUES (:user,:company,:currency) ON CONFLICT DO NOTHING', ['user'=>$userId,'company'=>$companyId,'currency'=>$currency]);
        $this->db->executeStatement('INSERT INTO commerce_fund_entries(account_id,refund_id,amount_minor,created_at) SELECT id,:refund,:amount,:now FROM commerce_fund_accounts WHERE owner_user_id IS NOT DISTINCT FROM :user AND owner_company_id IS NOT DISTINCT FROM :company AND currency=:currency', ['refund'=>$refundId,'amount'=>$amount,'now'=>$now,'user'=>$userId,'company'=>$companyId,'currency'=>$currency]);
    }
    public function fundBalance(?int $userId, ?int $companyId, string $currency): int
    {
        return (int)$this->db->fetchOne('SELECT COALESCE(SUM(e.amount_minor),0) FROM commerce_fund_accounts a LEFT JOIN commerce_fund_entries e ON e.account_id=a.id WHERE a.owner_user_id IS NOT DISTINCT FROM CAST(:user AS BIGINT) AND a.owner_company_id IS NOT DISTINCT FROM CAST(:company AS BIGINT) AND a.currency=:currency', ['user'=>$userId,'company'=>$companyId,'currency'=>$currency]);
    }
    public function revokeRefundedEntitlement(int $itemId, string $now): void
    {
        $this->db->executeStatement("UPDATE commerce_entitlements SET state='revoked' WHERE order_item_id=:item AND state<>'revoked'", ['item'=>$itemId]);
        $this->db->executeStatement("UPDATE course_enrolments SET status='cancelled',updated_at=:now WHERE id IN (SELECT enrolment_id FROM commerce_entitlements WHERE order_item_id=:item) AND status IN ('assigned','active')", ['item'=>$itemId,'now'=>$now]);
    }
    public function pdf(int $documentId): ?string
    {
        $encoded = $this->db->fetchOne('SELECT pdf_base64 FROM commerce_document_files WHERE document_id=:id', ['id'=>$documentId]);
        return is_string($encoded) ? (base64_decode($encoded, true) ?: null) : null;
    }
    public function savePdf(int $documentId, string $bytes): string
    {
        $this->db->executeStatement('INSERT INTO commerce_document_files(document_id,pdf_base64) VALUES (:id,:pdf) ON CONFLICT DO NOTHING', ['id'=>$documentId,'pdf'=>base64_encode($bytes)]);
        return $this->pdf($documentId) ?? throw new RuntimeException('Invoice PDF unavailable.');
    }
    /** @return array<string,mixed> */
    public static function decode(string $json): array { return json_decode($json,true,512,JSON_THROW_ON_ERROR); }
    /** @param array<string,mixed> $data */
    private static function json(array $data): string { return json_encode($data,JSON_THROW_ON_ERROR); }
}
