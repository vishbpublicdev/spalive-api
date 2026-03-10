<?php 
	declare(strict_types=1);

	namespace SpaLiveV1\Controller;
	use App\Controller\AppPluginController;
    use Stripe\Stripe;

	class ProductReceivedController extends AppPluginController {

		public function initialize() : void {
	        parent::initialize();
			$this->loadModel('SpaLiveV1.SysUsers');
			$this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
            $this->loadModel('SpaLiveV1.AppToken');
			//$this->loadModel('SpaLiveV1.DataPurchasesDetailOtherServices');
	    }

        public function refund_product_other_services(){
    
            $token = get('token','');
            if(!empty($token)){
                $user = $this->AppToken->validateToken($token, true);
                if($user === false){
                    $this->message('Invalid token.');
                    $this->set('session', false);
                    return;
                }
                $this->set('session', true);
            } else {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            
            $uid = get('uid','');
            $amount = get('amount', 0);
            
            $purchase = $this->DataPurchasesOtherServices->find()->where(['DataPurchasesOtherServices.uid' => $uid])->first();
            
            if(empty($purchase)){
                $this->message('Invalid purchase.');
                return;
            }

            if(!empty($purchase)){

                $this->success();
                
                $error = "";
                try {
                    if($amount > 0){
                        $re = \Stripe\Refund::create([
                            'amount' => $amount,
                            'payment_intent' => $purchase->payment,
                        ]);
    
                        if ($re) {
                            $this->success();
                        }
                    }else{
                        $this->message('Insufficient quantity.');
                    }   
                } catch(Stripe_CardError $e) {
                    $error = $e->getMessage();
                } catch (Stripe_InvalidRequestError $e) {
                    // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                } catch (Stripe_AuthenticationError $e) {
                    // Authentication with Stripe's API failed
                    $error = $e->getMessage();
                } catch (Stripe_ApiConnectionError $e) {
                    // Network communication with Stripe failed
                    $error = $e->getMessage();
                } catch (Stripe_Error $e) {
                    // Display a very generic error to the user, and maybe send
                    // yourself an email
                    $error = $e->getMessage();
                } catch (Exception $e) {
                    // Something else happened, completely unrelated to Stripe
                    $error = $e->getMessage();
                } catch(\Stripe\Exception\CardException $e) {
                    // Since it's a decline, \Stripe\Exception\CardException will be caught
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\RateLimitException $e) {
                    // Too many requests made to the API too quickly
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\AuthenticationException $e) {
                    // Authentication with Stripe's API failed
                    // (maybe you changed API keys recently)
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\ApiErrorException $e) {
                  // Display a very generic error to the user, and maybe send
                  // yourself an email
                  $error = $e->getMessage();
                }
                if(!empty($error)) $this->message($error);
            }
        }
    }
?>