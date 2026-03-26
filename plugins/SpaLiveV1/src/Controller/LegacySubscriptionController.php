<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;

use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;

/**
 * External/Legacy subscription activation API.
 *
 * Creates the 2 legacy subscription rows:
 * - SUBSCRIPTIONMSL
 * - SUBSCRIPTIONMD
 *
 * Also registers the split payments in data_subscription_payments.
 *
 * Optional: creates a product purchase (data_purchases + data_purchases_detail + data_payments)
 * via PaymentsController::createPaymentRegister().
 *
 * @property \SpaLiveV1\Model\Table\SysUsersTable $SysUsers
 * @property \SpaLiveV1\Model\Table\SysUserAdminTable $SysUserAdmin
 * @property \SpaLiveV1\Model\Table\DataSubscriptionsTable $DataSubscriptions
 * @property \SpaLiveV1\Model\Table\DataSubscriptionPaymentsTable $DataSubscriptionPayments
 * @property \SpaLiveV1\Model\Table\DataPurchasesTable $DataPurchases
 * @property \SpaLiveV1\Model\Table\DataPurchasesDetailTable $DataPurchasesDetail
 * @property \SpaLiveV1\Model\Table\CatProductsTable $CatProducts
 *
 * @method void loadModel(string $modelClass = null, string $modelType = null)
 */
class LegacySubscriptionController extends AppPluginController
{
    private function generateRandomString(int $length): string
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $out;
    }

    public function create_subscription()
    {
        // Auth (matches pattern used in other external endpoints).
        $apiKey = (string)get('api_key', '');
        $expectedApiKey = (string)Configure::read('App.external_api_key', '');
        if ($expectedApiKey === '' || $apiKey !== $expectedApiKey) {
            $this->set('success', false);
            $this->message('Invalid API key.');
            return;
        }

        $email = strtolower(trim((string)get('email', '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->set('success', false);
            $this->message('Valid email is required.');
            return;
        }

        // Payment reference: accept common external params.
        $paymentRef = (string)get('payment_ref', '');
        if ($paymentRef === '') {
            $paymentRef = (string)get('payment_intent', '');
        }
        if ($paymentRef === '') {
            $paymentRef = (string)get('charge_id', '');
        }
        if ($paymentRef === '') {
            $this->set('success', false);
            $this->message('payment_ref (or payment_intent/charge_id) is required.');
            return;
        }

        $totalAmount = (int)get('payment_amount', -1); // cents
        if ($totalAmount < 0) {
            $totalAmount = (int)get('total_amount', -1); // cents
        }
        if ($totalAmount < 0) {
            $this->set('success', false);
            $this->message('payment_amount (in cents) is required.');
            return;
        }

        // Mode switch (separate param, as requested):
        // - mode=SPLIT   => create BOTH legacy records (SUBSCRIPTIONMSL + SUBSCRIPTIONMD) and split payment_amount
        // - mode=SINGLE  => create ONE subscription record (subscription_type is kept exactly as provided) and do NOT split
        //
        // Backward compatible: create_dual=1 behaves like mode=SPLIT
        $mode = strtoupper(trim((string)get('mode', 'SINGLE'))); // SINGLE | SPLIT
        $createDualLegacyRecords = $mode === 'SPLIT' || ((int)get('create_dual', 0) === 1);

        // In SINGLE mode, subscription_type is REQUIRED (it can be the Port2Pay package title).
        // In SPLIT mode, subscription_type is optional and ignored for record creation.
        $subscriptionTypeRaw = trim((string)get('subscription_type', ''));
        if (!$createDualLegacyRecords && $subscriptionTypeRaw === '') {
            $this->set('success', false);
            $this->message('subscription_type is required when mode=SINGLE.');
            return;
        }

        // Optional explicit split (only used when dual-record mode is enabled).
        $mslAmount = (int)get('msl_amount', -1);
        $mdAmount = (int)get('md_amount', -1);
        if ($createDualLegacyRecords) {
            if ($mslAmount < 0 || $mdAmount < 0) {
                $mslAmount = (int)floor($totalAmount / 2);
                $mdAmount = $totalAmount - $mslAmount;
            }
            if ($mslAmount < 0 || $mdAmount < 0 || ($mslAmount + $mdAmount) !== $totalAmount) {
                $this->set('success', false);
                $this->message('Invalid split. Ensure msl_amount + md_amount = payment_amount (all in cents).');
                return;
            }
        }

        $monthly = (string)get('monthly', '1');
        if (!in_array($monthly, ['1', '3', '12'], true)) {
            $this->set('success', false);
            $this->message('monthly must be 1, 3, or 12.');
            return;
        }

        $mainService = (string)get('main_service', 'NEUROTOXINS');
        $addonsServices = (string)get('addons_services', '');
        $otherSchool = (int)get('other_school', 0);

        // payment_details can be sent as JSON object (preferred) or string.
        $paymentDetails = get('payment_details', null);
        if (is_string($paymentDetails) && $paymentDetails !== '') {
            $decoded = json_decode($paymentDetails, true);
            $paymentDetails = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($paymentDetails)) {
            $detailsJsonParam = get('payment_details_json', '');
            if (is_string($detailsJsonParam) && $detailsJsonParam !== '') {
                $decoded = json_decode($detailsJsonParam, true);
                if (is_array($decoded)) {
                    $paymentDetails = $decoded;
                }
            }
        }
        if (!is_array($paymentDetails)) {
            $paymentDetails = [$mainService => $totalAmount];
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

        $userEntity = $this->SysUsers->find()
            ->where(['SysUsers.email' => $email, 'SysUsers.deleted' => 0])
            ->first();

        if (empty($userEntity)) {
            $createUser = (int)get('create_user', 0) === 1;
            if (!$createUser) {
                $this->set('success', false);
                $this->message('User not found for this email.');
                return;
            }

            // Create new user (minimal fields, aligned with existing external user creation flow).
            $name = trim((string)get('name', ''));
            $lname = trim((string)get('lname', ''));
            $mname = trim((string)get('mname', ''));
            if ($name === '' || $lname === '') {
                $this->set('success', false);
                $this->message('name and lname are required when create_user=1.');
                return;
            }

            $phone = (string)get('phone', '');
            $stateId = (int)get('state', 0);
            $city = (string)get('city', '');
            $street = (string)get('street', '');
            $suite = (string)get('suite', '');
            $zip = (int)get('zip', 0);
            $dob = (string)get('dob', '2002-01-01');
            $userType = (string)get('user_type', 'injector');

            $arrDob = explode('-', $dob);
            $strDob = (count($arrDob) === 3) ? ($arrDob[0] . '-' . $arrDob[1] . '-' . $arrDob[2]) : '2002-01-01';

            // Generate unique short_uid like existing flow.
            do {
                $num = substr(str_shuffle('0123456789'), 0, 4);
                $shortUid = $num . strtoupper($this->generateRandomString(4));
                $existsShort = $this->SysUsers->find()
                    ->where(['SysUsers.short_uid LIKE' => $shortUid])
                    ->first();
            } while (!empty($existsShort));

            $newUid = Text::uuid();
            $newUserEntity = $this->SysUsers->newEntity([
                'uid' => $newUid,
                'short_uid' => $shortUid,
                'name' => $name,
                'mname' => $mname,
                'lname' => $lname,
                'email' => $email,
                'phone' => $phone,
                'type' => $userType,
                'state' => $stateId > 0 ? $stateId : 43,
                'city' => $city,
                'street' => $street,
                'suite' => $suite,
                'zip' => $zip,
                'dob' => $strDob,
                'active' => 1,
                'login_status' => 'READY',
                'steps' => 'HOME',
                'deleted' => 0,
                'createdby' => 0,
                'modifiedby' => 0,
                'photo_id' => 93,
                'score' => 0,
                'enable_notifications' => 1,
                'last_status_change' => date('Y-m-d H:i:s'),
                'password' => hash_hmac('sha256', Text::uuid(), Security::getSalt()),
            ]);

            if ($newUserEntity->hasErrors()) {
                $this->set('success', false);
                $this->message('User validation failed: ' . json_encode($newUserEntity->getErrors()));
                return;
            }
            $savedUser = $this->SysUsers->save($newUserEntity);
            if (!$savedUser) {
                $this->set('success', false);
                $this->message('Failed to create user.');
                return;
            }
            // Marker: users created by this endpoint will have createdby = their own id.
            // This allows LoginController to selectively bypass only these externally-created users.
            $this->SysUsers->updateAll(
                [
                    'createdby' => (int)$savedUser->id,
                    'modifiedby' => 0,
                ],
                ['id' => (int)$savedUser->id]
            );
            $userEntity = $savedUser;
        }

        // Ensure MD is assigned (used in subscription payments).
        $mdId = (int)($userEntity->md_id ?? 0);
        if ($mdId === 0) {
            $assigned = $this->SysUserAdmin->getAssignedDoctorInjector((int)$userEntity->id);
            $mdId = (int)($assigned ?? 0);
            if ($mdId > 0) {
                $this->SysUsers->updateAll(['md_id' => $mdId], ['id' => (int)$userEntity->id]);
            }
        }

        $now = date('Y-m-d H:i:s');

        $createdSubscriptions = [];
        $createdPayments = [];

        if ($createDualLegacyRecords) {
            // Create MSL + MD and split.
            $mslEntity = $this->DataSubscriptions->newEntity([
                'user_id' => (int)$userEntity->id,
                'uid' => Text::uuid(),
                'event' => 'external_subscription',
                'payload' => '',
                'request_id' => '',
                'data_object_id' => '',
                'customer_id' => '',
                'payment_method' => 'EXTERNAL',
                'subscription_type' => 'SUBSCRIPTIONMSL',
                'promo_code' => (string)get('promo_code', ''),
                'subtotal' => $mslAmount,
                'total' => $mslAmount,
                'status' => 'ACTIVE',
                'deleted' => 0,
                'created' => $now,
                'agreement_id' => 0,
                'comments' => '',
                'main_service' => $mainService,
                'addons_services' => $addonsServices,
                'payment_details' => json_encode($paymentDetails),
                'state' => (int)($userEntity->state ?? 0),
                'other_school' => $otherSchool,
                'monthly' => $monthly,
            ]);
            if ($mslEntity->hasErrors()) {
                $this->set('success', false);
                $this->message('MSL subscription validation failed: ' . json_encode($mslEntity->getErrors()));
                return;
            }
            $mslSaved = $this->DataSubscriptions->save($mslEntity);
            if (!$mslSaved) {
                $this->set('success', false);
                $this->message('Failed to save MSL subscription.');
                return;
            }
            $createdSubscriptions[] = $mslSaved;

            $mdEntity = $this->DataSubscriptions->newEntity([
                'user_id' => (int)$userEntity->id,
                'uid' => Text::uuid(),
                'event' => 'external_subscription',
                'payload' => '',
                'request_id' => '',
                'data_object_id' => '',
                'customer_id' => '',
                'payment_method' => 'EXTERNAL',
                'subscription_type' => 'SUBSCRIPTIONMD',
                'promo_code' => (string)get('promo_code', ''),
                'subtotal' => $mdAmount,
                'total' => $mdAmount,
                'status' => 'ACTIVE',
                'deleted' => 0,
                'created' => $now,
                'agreement_id' => 0,
                'comments' => '',
                'main_service' => $mainService,
                'addons_services' => $addonsServices,
                'payment_details' => json_encode($paymentDetails),
                'state' => (int)($userEntity->state ?? 0),
                'other_school' => $otherSchool,
                'monthly' => $monthly,
            ]);
            if ($mdEntity->hasErrors()) {
                $this->set('success', false);
                $this->message('MD subscription validation failed: ' . json_encode($mdEntity->getErrors()));
                return;
            }
            $mdSaved = $this->DataSubscriptions->save($mdEntity);
            if (!$mdSaved) {
                $this->set('success', false);
                $this->message('Failed to save MD subscription.');
                return;
            }
            $createdSubscriptions[] = $mdSaved;

            $mslPayEntity = $this->DataSubscriptionPayments->newEntity([
                'uid' => Text::uuid(),
                'user_id' => (int)$userEntity->id,
                'subscription_id' => (int)$mslSaved->id,
                'total' => $mslAmount,
                'payment_id' => $paymentRef,
                'charge_id' => $paymentRef,
                'receipt_id' => (string)get('receipt_url', $paymentRef),
                'error' => '',
                'status' => 'DONE',
                'notes' => 'External',
                'created' => $now,
                'deleted' => 0,
                'payment_type' => 'FULL',
                'payment_description' => 'SUBSCRIPTIONMSL',
                'main_service' => $mainService,
                'addons_services' => $addonsServices,
                'payment_details' => json_encode($paymentDetails),
                'state' => (int)($userEntity->state ?? 0),
                'md_id' => 0,
            ]);
            if (!$mslPayEntity->hasErrors()) {
                $createdPayments[] = $this->DataSubscriptionPayments->save($mslPayEntity);
            }

            $mdPayEntity = $this->DataSubscriptionPayments->newEntity([
                'uid' => Text::uuid(),
                'user_id' => (int)$userEntity->id,
                'subscription_id' => (int)$mdSaved->id,
                'total' => $mdAmount,
                'payment_id' => $paymentRef,
                'charge_id' => $paymentRef,
                'receipt_id' => (string)get('receipt_url', $paymentRef),
                'error' => '',
                'status' => 'DONE',
                'notes' => 'External',
                'created' => $now,
                'deleted' => 0,
                'payment_type' => 'FULL',
                'payment_description' => 'SUBSCRIPTIONMD',
                'main_service' => $mainService,
                'addons_services' => $addonsServices,
                'payment_details' => json_encode($paymentDetails),
                'state' => (int)($userEntity->state ?? 0),
                'md_id' => $mdId,
            ]);
            if (!$mdPayEntity->hasErrors()) {
                $createdPayments[] = $this->DataSubscriptionPayments->save($mdPayEntity);
            }
        } else {
            // Create a single subscription record using the provided title/type and do NOT split.
            $singleSub = $this->DataSubscriptions->newEntity([
                'user_id' => (int)$userEntity->id,
                'uid' => Text::uuid(),
                'event' => 'external_subscription',
                'payload' => '',
                'request_id' => '',
                'data_object_id' => '',
                'customer_id' => '',
                'payment_method' => 'EXTERNAL',
                'subscription_type' => trim((string)get('subscription_type', '')),
                'promo_code' => (string)get('promo_code', ''),
                'subtotal' => $totalAmount,
                'total' => $totalAmount,
                'status' => 'ACTIVE',
                'deleted' => 0,
                'created' => $now,
                'agreement_id' => 0,
                'comments' => '',
                'main_service' => $mainService,
                'addons_services' => $addonsServices,
                'payment_details' => json_encode($paymentDetails),
                'state' => (int)($userEntity->state ?? 0),
                'other_school' => $otherSchool,
                'monthly' => $monthly,
            ]);
            if ($singleSub->hasErrors()) {
                $this->set('success', false);
                $this->message('Subscription validation failed: ' . json_encode($singleSub->getErrors()));
                return;
            }
            $singleSaved = $this->DataSubscriptions->save($singleSub);
            if (!$singleSaved) {
                $this->set('success', false);
                $this->message('Failed to save subscription.');
                return;
            }
            $createdSubscriptions[] = $singleSaved;

            $singlePay = $this->DataSubscriptionPayments->newEntity([
                'uid' => Text::uuid(),
                'user_id' => (int)$userEntity->id,
                'subscription_id' => (int)$singleSaved->id,
                'total' => $totalAmount,
                'payment_id' => $paymentRef,
                'charge_id' => $paymentRef,
                'receipt_id' => (string)get('receipt_url', $paymentRef),
                'error' => '',
                'status' => 'DONE',
                'notes' => 'External',
                'created' => $now,
                'deleted' => 0,
                'payment_type' => 'FULL',
                'payment_description' => trim((string)get('subscription_type', '')),
                'main_service' => $mainService,
                'addons_services' => $addonsServices,
                'payment_details' => json_encode($paymentDetails),
                'state' => (int)($userEntity->state ?? 0),
                'md_id' => $mdId,
            ]);
            if (!$singlePay->hasErrors()) {
                $createdPayments[] = $this->DataSubscriptionPayments->save($singlePay);
            }
        }

        // Optional product purchase.
        $createProductPurchase = (int)get('create_product_purchase', 0) === 1;
        if ($createProductPurchase) {
            $this->loadModel('SpaLiveV1.DataPurchases');
            $this->loadModel('SpaLiveV1.DataPurchasesDetail');
            $this->loadModel('SpaLiveV1.CatProducts');

            $productId = (int)get('product_id', 0);
            $productAmount = (int)get('product_amount', 0);
            if ($productId > 0) {
                $purchaseUid = Text::uuid();
                $purchaseEntity = $this->DataPurchases->newEntity([
                    'uid' => $purchaseUid,
                    'user_id' => (int)$userEntity->id,
                    'status' => 'NEW',
                    'name' => trim((string)$userEntity->name . ' ' . (string)$userEntity->lname),
                    'address' => '',
                    'suite' => '',
                    'city' => '',
                    'state' => '',
                    'zip' => 0,
                    'tracking' => '',
                    'delivery_company' => '',
                    'created' => $now,
                    'shipping_date' => date('Y-m-d'),
                    'shipping_cost' => 0,
                    'amount' => $productAmount,
                ]);

                if (!$purchaseEntity->hasErrors() && $this->DataPurchases->save($purchaseEntity)) {
                    $product = $this->CatProducts->find()
                        ->where(['CatProducts.id' => $productId])
                        ->first();

                    if (!empty($product)) {
                        $detailEntity = $this->DataPurchasesDetail->newEntity([
                            'product_id' => (int)$product->id,
                            'price' => (int)$product->unit_price,
                            'qty' => 1,
                            'product_detail_question' => (string)$product->details_text,
                            'product_detail' => '',
                            'purchase_id' => (int)$purchaseEntity->id,
                        ]);
                        if (!$detailEntity->hasErrors()) {
                            $this->DataPurchasesDetail->save($detailEntity);
                        }
                    }

                    $Payments = new PaymentsController();
                    $Payments->createPaymentRegister(
                        'PURCHASE',
                        (int)$userEntity->id,
                        0,
                        $purchaseUid,
                        $paymentRef,
                        $paymentRef,
                        (string)get('receipt_url', $paymentRef),
                        $productAmount,
                        $productAmount
                    );

                    $this->DataPurchases->updateAll(
                        [
                            'payment' => $paymentRef,
                            'payment_intent' => $paymentRef,
                            'receipt_url' => (string)get('receipt_url', $paymentRef),
                        ],
                        ['uid' => $purchaseUid]
                    );
                }
            }
        }

        // Ensure user can access the app.
        $this->SysUsers->updateAll(
            [
                'active' => 1,
                'login_status' => 'READY',
                'steps' => 'HOME',
            ],
            ['id' => (int)$userEntity->id]
        );

        $this->set('success', true);
        $this->set('user_id', (int)$userEntity->id);
        $this->set('mode', $createDualLegacyRecords ? 'SPLIT' : 'SINGLE');
        $this->set('subscription_type', $subscriptionTypeRaw);
        $this->set(
            'subscriptions',
            array_map(
                function ($s) {
                    return [
                        'id' => (int)$s->id,
                        'uid' => (string)$s->uid,
                        'subscription_type' => (string)$s->subscription_type,
                        'total' => (int)$s->total,
                    ];
                },
                $createdSubscriptions
            )
        );
        $this->set('payment_ref', $paymentRef);
        $this->success();
    }
}

