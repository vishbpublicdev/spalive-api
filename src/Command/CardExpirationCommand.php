<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

use SpaLiveV1\Controller\MainController;

class CardExpirationCommand extends Command{

    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }
    
    public function execute(Arguments $args, ConsoleIo $io){

        $arr_arguments = $args->getArguments();
        $isDev = env('IS_DEV', false);        
        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        

        $users = $this->SysUsers->find()->select(['SysUsers.id', 'SysUsers.name', 'SysUsers.mname', 'SysUsers.lname', 'SysUsers.email', 'SysUsers.type'])->where(['SysUsers.deleted' => 0, 'SysUsers.active' => 1])
        ->order(['SysUsers.id' => 'DESC'])
        ->all();
        $this->log(__LINE__ .' users '. count($users));
        $i = 0;
        foreach($users as $user) {
             $this->log(__LINE__ . ' ' . json_encode($i));
             $i++;
            //$this->log(__LINE__ . ' ' . json_encode($user->type));
            //$this->log(__LINE__ .' email '. $user->email);
            $methods_array = array();
            $preferred_method = '';
            $entMethod = $this->DataSubscriptionMethodPayments->find()->where(['DataSubscriptionMethodPayments.user_id' => $user->id , 'DataSubscriptionMethodPayments.deleted' => 0])->first();
            if (!empty($entMethod)) {
                $preferred_method = $entMethod->payment_id;
                //$this->log(__LINE__ .' preferred_method '. $preferred_method);
            }

            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));             
            $oldCustomer = $stripe->customers->all([
                "email" => $user->email,//'gissel.fabela@yahoo.com',//
                "limit" => 1,
            ]);
             //$this->log(__LINE__ . ' ' . json_encode($oldCustomer));
            
                    if (count($oldCustomer) > 0) {
                        //$this->log(__LINE__ .' oldCustomer '. $oldCustomer);
                        $customer = $oldCustomer->data[0];  
                        $payment_methods = $stripe->customers->allPaymentMethods(
                            $customer->id,
                            ['type' => 'card']
                        );
                    
                        if (empty($entMethod) && count($payment_methods) > 0) {                
                            $preferred_method = $payment_methods->data[0]->id;                
                        }
                        
                        foreach($payment_methods as $method) {
                            $preferred = $method->id == $preferred_method ? 1 : 0;
                             //$this->log(__LINE__ . ' ' . json_encode($method->id ." ". $preferred_method));
                            if($preferred == 1) {
                                //$this->log(__LINE__ .' method '. $method->card);                                                                                                
                                $exp_month = $method->card->exp_month;                                 
                                $exp_year = $method->card->exp_year;                                 
                                $mes = $exp_month; // Ejemplo: octubre 10; // Ejemplo: octubre
                                $año = $exp_year;#$año"  2023; // Ejemplo: 2023
                                //$this->log(__LINE__ . ' ' . json_encode($año ." ". $mes));
                                // Crear una fecha a partir del mes y año especificados
                                $fechaFinal = new \DateTime("$año-$mes-01");
                                // Obtener la fecha actual
                                $fechaInicial = new \DateTime();                                
                                // Calcula la diferencia entre las dos fechas
                                $diferencia = $fechaInicial->diff($fechaFinal);
                                // Obtiene el número de días de diferencia
                                $diasDiferencia = $diferencia->days * ($fechaInicial > $fechaFinal ? -1 : 1);
                                //$this->log(__LINE__ .' diasDiferencia '. $diasDiferencia);
                                // Comprueba si la diferencia es de exactamente 7 días
                                if ($diasDiferencia == 7) {
                                    $this->log(__LINE__ .' diasDiferencia '. $diasDiferencia);
                                    // verificar que si es injector tenga activa la subscripcion
                                    // Enviar correo echo "¡Hay una diferencia de exactamente 7 días!";
                                    if ($user->type == 'injector'){                                                                                                                        
                                        $subscription = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user->id])->first();
                                        if (!empty($subscription)) {
                                            if($subscription->status == 'ACTIVE'){
                                                $this->log(__LINE__ . ' ' . json_encode('subscription active'));
                                                // Enviar correo
                                                $this->sendEmail($user->email,$user->name . " " . $user->mname . " " . $user->lname);           
                                            }
                                        }
                                    }else{
                                        //enviar correo
                                        $this->sendEmail($user->email,$user->name . " " . $user->mname . " " . $user->lname);           
                                    }   
                                }                         
                        }else if($type = "patient"){
                            //$this->log(__LINE__ .' method '. $method->card);                                                                                                
                                $exp_month = $method->card->exp_month;                                 
                                $exp_year = $method->card->exp_year;                                 
                                $mes = $exp_month; // Ejemplo: octubre 10; // Ejemplo: octubre
                                $año = $exp_year;#$año"  2023; // Ejemplo: 2023
                                //$this->log(__LINE__ . ' ' . json_encode($año ." ". $mes));
                                // Crear una fecha a partir del mes y año especificados
                                $fechaFinal = new \DateTime("$año-$mes-01");
                                // Obtener la fecha actual
                                $fechaInicial = new \DateTime();                                
                                // Calcula la diferencia entre las dos fechas
                                $diferencia = $fechaInicial->diff($fechaFinal);
                                // Obtiene el número de días de diferencia
                                $diasDiferencia = $diferencia->days * ($fechaInicial > $fechaFinal ? -1 : 1);
                                //$this->log(__LINE__ .' diasDiferencia '. $diasDiferencia);
                                // Comprueba si la diferencia es de exactamente 7 días
                                if ($diasDiferencia == 7) {
                                    $this->log(__LINE__ .' diasDiferencia '. $diasDiferencia);
                                    // verificar que si es injector tenga activa la subscripcion
                                    // verificar que si es injector tenga activa la subscripcion                                    
                                    if ($user->type == 'injector'){                                                                                                                        
                                        $subscription = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user->id])->first();
                                        if (!empty($subscription)) {
                                            if($subscription->status == 'ACTIVE'){
                                                $this->log(__LINE__ . ' ' . json_encode('subscription active'));
                                                // Enviar correo                                                
                                                $this->sendEmail($user->email,$user->name . " " . $user->mname . " " . $user->lname);           
                                            }
                                        }
                                    }else{
                                        //enviar correo
                                        $this->log(__LINE__ . ' ' . json_encode($user->email));
                                        $this->log(__LINE__ . ' ' . json_encode($user->name));
                                        $this->log(__LINE__ . ' ' . json_encode($user->type));
                                        $this->sendEmail($user->email,$user->name . " " . $user->mname . " " . $user->lname);           
                                    }
                                }
                        }
                    }
            }                                    
        }       
    
    }
   


    

    private function sendEmail($email,$name) {
        $isDev = env('IS_DEV', false);

        if($isDev) {
            $path  = 'https://blog.myspalive.com/check_preferences.php?src=dev';
        }else{
            $path  = 'https://blog.myspalive.com/check_preferences.php?src=prod';
        }
        
        $body = '
        <!doctype html>
        <html>
            <head>
            <meta name="viewport" content="width=device-width">
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
            <title>MySpaLive Message</title>
            <style>
            @media only screen and (max-width: 620px) {
                table[class=body] h1 {
                font-size: 28px !important;
                margin-bottom: 10px !important;
                }
                table[class=body] p,
                    table[class=body] ul,
                    table[class=body] ol,
                    table[class=body] td,
                    table[class=body] span,
                    table[class=body] a {
                font-size: 16px !important;
                }
                table[class=body] .wrapper,
                    table[class=body] .article {
                padding: 10px !important;
                }
                table[class=body] .content {
                padding: 0 !important;
                }
                table[class=body] .container {
                padding: 0 !important;
                width: 100% !important;
                }
                table[class=body] .main {
                border-left-width: 0 !important;
                border-radius: 0 !important;
                border-right-width: 0 !important;
                }
                table[class=body] .btn table {
                width: 100% !important;
                }
                table[class=body] .btn a {
                width: 100% !important;
                }
                table[class=body] .img-responsive {
                height: auto !important;
                max-width: 100% !important;
                width: auto !important;
                }
            }
            /* -------------------------------------
                PRESERVE THESE STYLES IN THE HEAD
            ------------------------------------- */
            @media all {
                .ExternalClass {
                width: 100%;
                }
                .ExternalClass,
                    .ExternalClass p,
                    .ExternalClass span,
                    .ExternalClass font,
                    .ExternalClass td,
                    .ExternalClass div {
                line-height: 100%;
                }
                .apple-link a {
                color: inherit !important;
                font-family: inherit !important;
                font-size: inherit !important;
                font-weight: inherit !important;
                line-height: inherit !important;
                text-decoration: none !important;
                }
                #MessageViewBody a {
                color: inherit;
                text-decoration: none;
                font-size: inherit;
                font-family: inherit;
                font-weight: inherit;
                line-height: inherit;
                }
                .btn-primary table td:hover {
                background-color: #34495e !important;
                }
                .btn-primary a:hover {
                background-color: #34495e !important;
                border-color: #34495e !important;
                }
            }
            </style>
            </head>
            <body class="" style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
            <span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">MySpaLive Message.</span>
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
                <tr>
                <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                <td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
                    <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">                                
                    <br><br>
                    <!-- START CENTERED WHITE CONTAINER -->
                    <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 30px;">
                        <!-- START MAIN CONTENT AREA -->
                        <tr>
                        <td class="wrapper" style="font-family: sans-serif; font-size: 14px; box-sizing: border-box; padding: 20px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                            <tr>
                                <div style="padding-top: 2vw;">
                                    <center>
                                        <img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="200px"/>
                                    </center><br>
                                </div>

                                <p>Dear '.$name.',</p>
                                <p>We noticed that the payment method on your MySpaLive account is nearing its expiration date. To avoid any interruptions in accessing our premier services, please update your payment information at your earliest convenience. <a href="'.$path.'">Click here to update your payment information.</a> </p>
                                <p><b>Need Assistance?</b><br>
                                If you encounter any issues or have questions, please don\'t hesitate to contact our support team at <a href="mailto:support@myspalive.com">support@myspalive.com</a> or 469-277-0897. We\'re here to assist you!</p>
                                <p>Thank you for your attention to this matter, and we look forward to continuing to serve you with our exceptional aesthetic services.</p>
                                <p>Best,<br>
                                The MySpaLive Team</p> 
                                
                            </tr>
                            </table>
                        </td>
                        </tr>

                    <!-- END MAIN CONTENT AREA -->
                    <br><br>
                    </table>

                    <!-- START FOOTER -->
                    <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                        <tr>
                            <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                            <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a href="https://blog.myspalive.com/">MySpaLive</a></span>
                            </td>
                        </table>
                    </div>
                    <!-- END FOOTER -->

                    <!-- END CENTERED WHITE CONTAINER -->
                    </div>
                </td>
                <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                </tr>
            </table>
            </body>
        </html>';
            
        
        $this->log(__LINE__ . ' ' . json_encode($email));
        $data = array(
            'from'    => 'MySpaLive <info@mg.myspalive.com>',
            // 'to'    => 'khanzab@gmail.com',
            'to'    => $email,
            'bcc'      => 'francisco@advantedigital.com',
            'subject' => "Friendly Reminder: Your Payment Method is Expiring Soon!",
            'html'    => $body,
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($curl);
        curl_close($curl);
    }

}