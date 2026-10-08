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

    private const BUNDLE_OFFER = 'SELECT o.id, o.bundle_id, o.price_minor_units, o.currency_code, o.access_period_seconds, o.is_active AS offer_active,
            b.title, b.slug, b.status, b.available_from, b.available_until
        FROM bundle_offers o JOIN bundles b ON b.id = o.bundle_id';

    /**
     * The cart's bundle lines: each bundle offer with its bundle, in the order they were added.
     *
     * @return list<array<string,mixed>>
     */
    public function cartBundles(int $cartId): array
    {
        return $this->db->fetchAllAssociative(self::BUNDLE_OFFER . ' JOIN commerce_cart_bundles cb ON cb.bundle_offer_id = o.id WHERE cb.cart_id=:id ORDER BY o.id', ['id'=>$cartId]);
    }

    /** @return array<string,mixed> */
    public function bundleOffer(int $offerId): array
    {
        return $this->db->fetchAssociative(self::BUNDLE_OFFER . ' WHERE o.id=:id', ['id'=>$offerId]) ?: throw new RuntimeException('The bundle does not exist.');
    }

    /**
     * A bundle's courses as they are now, in order. Placement copies them into the order line.
     *
     * @return list<array<string,mixed>>
     */
    public function bundleCourses(int $bundleId): array
    {
        return $this->db->fetchAllAssociative('SELECT c.id AS course_id, c.title, c.slug, c.status FROM bundle_courses bc JOIN courses c ON c.id=bc.course_id WHERE bc.bundle_id=:id ORDER BY bc.position', ['id'=>$bundleId]);
    }

    public function changeCartBundle(int $cartId, int $offerId, bool $remove): void
    {
        if ($remove) {
            $this->db->executeStatement('DELETE FROM commerce_cart_bundles WHERE cart_id=:cart AND bundle_offer_id=:offer', ['cart'=>$cartId,'offer'=>$offerId]);
        } else {
            $this->db->executeStatement('INSERT INTO commerce_cart_bundles(cart_id,bundle_offer_id) VALUES (:cart,:offer) ON CONFLICT DO NOTHING', ['cart'=>$cartId,'offer'=>$offerId]);
        }
        $this->db->executeStatement('UPDATE commerce_carts SET revision=revision+1 WHERE id=:id', ['id'=>$cartId]);
    }

    /** Whether an order that is not cancelled or refunded already sells this bundle to the learner. */
    public function hasPayableBundle(int $userId, int $bundleId): bool
    {
        return (bool)$this->db->fetchOne("SELECT i.id FROM commerce_order_items i JOIN commerce_orders o ON o.id=i.order_id WHERE i.beneficiary_user_id=:user AND i.bundle_id=:bundle AND o.state IN ('placed','awaiting_payment','manual_review')", ['user'=>$userId,'bundle'=>$bundleId]);
    }

    /** The learner's open enrolment in the course, from any source. */
    public function openEnrolment(int $userId, int $courseId): ?int
    {
        $id = $this->db->fetchOne("SELECT id FROM course_enrolments WHERE user_id=:user AND course_id=:course AND NOT is_preview AND status IN ('assigned','active','completed') ORDER BY id LIMIT 1", ['user'=>$userId,'course'=>$courseId]);
        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Records the entitlement source a bundle line created for one of its courses, the visible
     * enrolment it belongs to, and whether another source already granted the course then. Once per
     * line and course; returns false when already recorded.
     */
    public function recordBundleGrant(int $itemId, int $courseId, int $enrolmentId, int $entitlementId, bool $shared, string $now): bool
    {
        return $this->db->fetchOne('INSERT INTO commerce_bundle_grants(order_item_id,course_id,enrolment_id,entitlement_id,shared_at_grant,created_at) VALUES (:item,:course,:enrolment,:entitlement,:shared,:now) ON CONFLICT DO NOTHING RETURNING 1', ['item'=>$itemId,'course'=>$courseId,'enrolment'=>$enrolmentId,'entitlement'=>$entitlementId,'shared'=>$shared?'t':'f','now'=>$now]) !== false;
    }

    /** @return list<array<string,mixed>> the line's grants with the state of the source each created and of the enrolment */
    public function bundleGrants(int $itemId): array
    {
        return $this->db->fetchAllAssociative('SELECT g.*, e.state AS entitlement_state, e.access_expires_at, ce.status AS enrolment_status FROM commerce_bundle_grants g JOIN commerce_entitlements e ON e.id=g.entitlement_id JOIN course_enrolments ce ON ce.id=g.enrolment_id WHERE g.order_item_id=:item ORDER BY g.created_at, g.course_id', ['item'=>$itemId]);
    }

    /** Applies or removes the cart's promo code; like any cart change it invalidates an earlier quote. */
    public function setCartPromotion(int $cartId, ?int $promotionId): void
    {
        $this->db->executeStatement('UPDATE commerce_carts SET promotion_id=:promotion, revision=revision+1 WHERE id=:id', ['id'=>$cartId,'promotion'=>$promotionId]);
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

    /**
     * The total is after the discount; the promotion and its discount are recorded beside it.
     *
     * @param array<string,mixed> $snapshot
     */
    public function place(int $cartId, int $userId, int $total, string $currency, array $snapshot, string $now, string $due, ?int $promotionId = null, int $discountMinor = 0): int
    {
        $id = (int) $this->db->fetchOne("INSERT INTO commerce_orders(public_id,purchaser_user_id,cart_id,state,total_minor,currency,snapshot,placed_at,payment_due_at,promotion_id,discount_minor) VALUES (:public,:user,:cart,'placed',:total,:currency,:snapshot,:now,:due,:promotion,:discount) RETURNING id", ['public'=>Uuid::v4(),'user'=>$userId,'cart'=>$cartId,'total'=>$total,'currency'=>$currency,'snapshot'=>self::json($snapshot),'now'=>$now,'due'=>$due,'promotion'=>$promotionId,'discount'=>$discountMinor]);
        $this->db->executeStatement("UPDATE commerce_carts SET state='placed' WHERE id=:id", ['id'=>$cartId]);
        return $id;
    }

    /**
     * amount_minor is the line's price; discount_minor its share of the order's promotion discount.
     *
     * @param array<string,mixed> $snapshot
     */
    public function addOrderItem(int $orderId, int $userId, array $snapshot): void
    {
        if (($snapshot['fulfilment_type'] ?? '') === 'bundle') {
            $this->db->executeStatement("INSERT INTO commerce_order_items(order_id,product_type,bundle_id,bundle_offer_id,beneficiary_user_id,amount_minor,discount_minor,access_period_seconds,snapshot) VALUES (:order,'bundle',:bundle,:offer,:user,:amount,:discount,:duration,:snapshot)", ['order'=>$orderId,'bundle'=>$snapshot['bundle_id'],'offer'=>$snapshot['bundle_offer_id'],'user'=>$userId,'amount'=>$snapshot['unit_price_minor'],'discount'=>(int)($snapshot['discount_minor'] ?? 0),'duration'=>$snapshot['access_period_seconds'],'snapshot'=>self::json($snapshot)]);
            return;
        }
        $this->db->executeStatement('INSERT INTO commerce_order_items(order_id,variant_id,course_id,beneficiary_user_id,amount_minor,discount_minor,access_period_seconds,snapshot) VALUES (:order,:variant,:course,:user,:amount,:discount,:duration,:snapshot)', ['order'=>$orderId,'variant'=>$snapshot['variant_id'],'course'=>$snapshot['course_id'],'user'=>$userId,'amount'=>$snapshot['unit_price_minor'],'discount'=>(int)($snapshot['discount_minor'] ?? 0),'duration'=>$snapshot['access_period_seconds'],'snapshot'=>self::json($snapshot)]);
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
    /**
     * An order's financial documents, oldest first, each with the name of the template that drew it.
     *
     * @return list<array<string,mixed>>
     */
    public function documents(int $orderId): array
    {
        return $this->db->fetchAllAssociative('SELECT d.*, t.name AS template_name FROM commerce_documents d JOIN document_templates t ON t.id=d.template_id WHERE d.order_id=:id ORDER BY d.id', ['id'=>$orderId]);
    }
    /** @return array<string,mixed>|null the document issued for a source (an order, a payment, a refund), if it was */
    public function documentForSource(string $sourceKey): ?array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_documents WHERE source_key=:key', ['key'=>$sourceKey]) ?: null;
    }
    /** The next number of a kind of document, taken for good: INV-00000001, REC-…, CN-…. */
    public function nextDocumentNumber(string $kind): string
    {
        $prefix = match ($kind) { 'invoice'=>'INV-', 'receipt'=>'REC-', 'credit_note'=>'CN-', default=>throw new RuntimeException('Unknown financial document type.') };
        $number = $this->db->fetchOne('UPDATE commerce_document_numbers SET next_number=next_number+1 WHERE kind=:kind RETURNING next_number-1', ['kind'=>$kind]);
        if ($number === false) throw new RuntimeException('Unknown financial document type.');
        return $prefix.str_pad((string)$number,8,'0',STR_PAD_LEFT);
    }
    /**
     * @param array<string,mixed> $snapshot the commerce record it was issued from
     * @param array<string,mixed> $data every value it prints
     * @param array{template_id:?int,template_version_id:?int,template_version_number:?int} $reference the template version that drew it
     */
    public function insertDocument(int $orderId, string $kind, string $number, string $sourceKey, array $snapshot, array $data, array $reference, string $issuedAt): int
    {
        return (int) $this->db->fetchOne('INSERT INTO commerce_documents(public_id,order_id,kind,number,source_key,snapshot,document_data,template_id,template_version_id,template_version_number,issued_at) VALUES (:public,:order,:kind,:number,:key,:snapshot,:data,:template,:version,:version_number,:now) RETURNING id',
            ['public'=>Uuid::v4(),'order'=>$orderId,'kind'=>$kind,'number'=>$number,'key'=>$sourceKey,'snapshot'=>self::json($snapshot),'data'=>self::json($data),
                'template'=>$reference['template_id'],'version'=>$reference['template_version_id'],'version_number'=>$reference['template_version_number'],'now'=>$issuedAt]);
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
    /**
     * The visible enrolment access is resolved for, locked when asked.
     *
     * @return array<string,mixed>|null
     */
    public function accessEnrolment(int $enrolmentId, bool $lock = false): ?array
    {
        return $this->db->fetchAssociative('SELECT id,user_id,course_id,source_type,status,started_at,expires_at,assigned_at,access_period_seconds FROM course_enrolments WHERE id=:id' . ($lock?' FOR UPDATE':''), ['id'=>$enrolmentId]) ?: null;
    }

    /**
     * Every entitlement source behind an enrolment, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function sources(int $enrolmentId, bool $lock = false): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM commerce_entitlements WHERE enrolment_id=:id ORDER BY id' . ($lock?' FOR UPDATE':''), ['id'=>$enrolmentId]);
    }

    /** @return array<string,mixed>|null */
    public function source(int $sourceId, bool $lock = false): ?array
    {
        return $this->db->fetchAssociative('SELECT * FROM commerce_entitlements WHERE id=:id' . ($lock?' FOR UPDATE':''), ['id'=>$sourceId]) ?: null;
    }

    /** @return list<int> the sources an order line created */
    public function sourcesForItem(int $itemId): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM commerce_entitlements WHERE order_item_id=:item ORDER BY id', ['item'=>$itemId]));
    }

    public function fulfilledItem(int $itemId): bool
    {
        return (bool)$this->db->fetchOne('SELECT id FROM commerce_entitlements WHERE order_item_id=:id', ['id'=>$itemId]);
    }

    /**
     * A new visible enrolment with one entitlement source: what an individual purchase or a free
     * acceptance creates. Returns the enrolment.
     *
     * @param array<string,mixed> $snapshot
     */
    public function createEntitlement(?int $itemId, int $userId, int $courseId, int $duration, string $source, array $snapshot, string $now, string $deadline): int
    {
        $enrolment = $this->createEnrolment($userId, $courseId, $duration, $source, $itemId, $now);
        $this->addSource($itemId, $enrolment, $source, $duration, $snapshot, $now, $deadline);
        return $enrolment;
    }

    /** A visible enrolment for a learner who has no open access to the course yet. */
    public function createEnrolment(int $userId, int $courseId, int $duration, string $sourceType, ?int $itemId, string $now): int
    {
        return (int)$this->db->fetchOne("INSERT INTO course_enrolments(public_id,user_id,course_id,source_type,source_reference,status,access_period_seconds,assigned_at,created_at,updated_at) VALUES (:public,:user,:course,:source,:reference,'assigned',:duration,:now,:now,:now) RETURNING id", ['public'=>Uuid::v4(),'user'=>$userId,'course'=>$courseId,'source'=>$sourceType,'reference'=>$itemId===null?null:(string)$itemId,'duration'=>$duration,'now'=>$now]);
    }

    /**
     * One more entitlement source behind an enrolment, awaiting activation with its own access period.
     *
     * @param array<string,mixed> $snapshot
     */
    public function addSource(?int $itemId, int $enrolmentId, string $source, int $duration, array $snapshot, string $now, string $deadline): int
    {
        return (int)$this->db->fetchOne("INSERT INTO commerce_entitlements(order_item_id,enrolment_id,state,source,snapshot,created_at,activation_deadline_at,access_period_seconds) VALUES (:item,:enrolment,'awaiting_activation',:source,:snapshot,:now,:deadline,:duration) RETURNING id", ['item'=>$itemId,'enrolment'=>$enrolmentId,'source'=>$source,'snapshot'=>self::json($snapshot),'now'=>$now,'deadline'=>$deadline,'duration'=>$duration]);
    }

    /**
     * Records an enrolment's own non-commerce term (ADMIN assignment, company credit, seed) as its
     * 'origin' source, the first time a commerce source joins it, so the two stay independent. A
     * term already running (or preset) is active until it ends; one not yet started starts when the
     * learner starts. Does nothing for an enrolment that already has sources.
     */
    public function recordOriginSource(int $enrolmentId, string $now): void
    {
        $this->db->executeStatement(
            "INSERT INTO commerce_entitlements(order_item_id,enrolment_id,state,source,snapshot,created_at,activation_deadline_at,access_started_at,access_expires_at,access_period_seconds)
             SELECT NULL, ce.id,
                    CASE WHEN ce.expires_at IS NULL THEN 'awaiting_activation' WHEN ce.expires_at <= CAST(:now AS TIMESTAMPTZ) THEN 'expired' ELSE 'active' END,
                    'origin', jsonb_build_object('source_type', ce.source_type, 'source_reference', ce.source_reference), ce.assigned_at, NULL,
                    CASE WHEN ce.expires_at IS NULL THEN NULL ELSE LEAST(COALESCE(ce.started_at, ce.assigned_at), ce.expires_at - INTERVAL '1 second') END,
                    ce.expires_at, ce.access_period_seconds
               FROM course_enrolments ce
              WHERE ce.id=:id AND NOT EXISTS (SELECT 1 FROM commerce_entitlements e WHERE e.enrolment_id=ce.id)",
            ['id'=>$enrolmentId,'now'=>$now]
        );
    }

    public function activateSource(int $sourceId, string $state, string $start, string $expiry): void
    {
        $this->db->executeStatement('UPDATE commerce_entitlements SET state=:state,access_started_at=:start,access_expires_at=:expiry WHERE id=:id', ['id'=>$sourceId,'state'=>$state,'start'=>$start,'expiry'=>$expiry]);
    }

    public function expireSource(int $sourceId): void
    {
        $this->db->executeStatement("UPDATE commerce_entitlements SET state='expired' WHERE id=:id", ['id'=>$sourceId]);
    }

    /** Returns false when the source was already revoked. */
    public function revokeSource(int $sourceId): bool
    {
        return $this->db->executeStatement("UPDATE commerce_entitlements SET state='revoked' WHERE id=:id AND state<>'revoked'", ['id'=>$sourceId]) > 0;
    }

    /**
     * The enrolment's expires_at is a projection of its sources: the latest expiry among the active
     * ones, null while sources only await activation. The library, reports and legacy checks read it.
     */
    public function projectAccess(int $enrolmentId, ?string $expiresAt): void
    {
        $this->db->executeStatement('UPDATE course_enrolments SET expires_at=CAST(:expiry AS TIMESTAMPTZ) WHERE id=:id AND expires_at IS DISTINCT FROM CAST(:expiry AS TIMESTAMPTZ)', ['id'=>$enrolmentId,'expiry'=>$expiresAt]);
    }

    /** Ends the visible access when no source keeps it open. Learning history remains. */
    public function cancelEnrolment(int $enrolmentId, string $now): void
    {
        $this->db->executeStatement("UPDATE course_enrolments SET status='cancelled',updated_at=:now WHERE id=:id AND status IN ('assigned','active')", ['id'=>$enrolmentId,'now'=>$now]);
    }

    public function learnerStarted(int $enrolmentId, string $now): void
    {
        $this->db->executeStatement("UPDATE course_enrolments SET started_at=COALESCE(started_at,:now),status=CASE WHEN status='assigned' THEN 'active' ELSE status END,updated_at=:now WHERE id=:id", ['id'=>$enrolmentId,'now'=>$now]);
    }
    /** @return list<int> */
    public function dueEntitlements(string $now, int $limit): array
    {
        return array_map('intval',$this->db->fetchFirstColumn("SELECT enrolment_id FROM commerce_entitlements WHERE (state='awaiting_activation' AND activation_deadline_at<=:now) OR (state='active' AND access_expires_at<=:now) GROUP BY enrolment_id ORDER BY MIN(id) LIMIT :limit", ['now'=>$now,'limit'=>$limit]));
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
    public function pdf(int $documentId): ?string
    {
        $encoded = $this->db->fetchOne('SELECT pdf_base64 FROM commerce_document_files WHERE document_id=:id', ['id'=>$documentId]);
        return is_string($encoded) ? (base64_decode($encoded, true) ?: null) : null;
    }
    public function savePdf(int $documentId, string $bytes): string
    {
        $this->db->executeStatement('INSERT INTO commerce_document_files(document_id,pdf_base64) VALUES (:id,:pdf) ON CONFLICT DO NOTHING', ['id'=>$documentId,'pdf'=>base64_encode($bytes)]);
        return $this->pdf($documentId) ?? throw new RuntimeException('The document PDF is unavailable.');
    }
    /** @return array<string,mixed> */
    public static function decode(string $json): array { return json_decode($json,true,512,JSON_THROW_ON_ERROR); }
    /** @param array<string,mixed> $data */
    private static function json(array $data): string { return json_encode($data,JSON_THROW_ON_ERROR); }
}
