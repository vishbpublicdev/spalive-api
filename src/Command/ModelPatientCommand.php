<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

class ModelpatientCommand extends Command{
    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }

    public function execute(Arguments $args, ConsoleIo $io){
        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.DataModelPatient');

        $query_trainings = "
            SELECT 
                CT.id,
                (CT.models_per_class - (CASE WHEN MP.cant_models IS NULL THEN 0 ELSE MP.cant_models END)) available
            FROM cat_trainings CT
                LEFT JOIN (
                    SELECT 
                        COUNT(id) cant_models,
                        registered_training_id
                    FROM data_model_patient 
                    WHERE deleted = 0
                    GROUP BY registered_training_id
                ) MP ON MP.registered_training_id = CT.id
            WHERE (CT.scheduled > CURDATE())
                AND (CT.deleted = 0)
                AND ((CASE WHEN MP.cant_models IS NULL THEN 0 ELSE MP.cant_models END) < CT.models_per_class)
        ";

        $trainings = $this->CatTrainings->getConnection()->execute($query_trainings)->fetchAll('assoc');

        foreach($trainings as $training)
        {
            $training_id = $training['id'];
            $available = $training['available'];

            $query_model_patients = "
                SELECT MP.id
                FROM data_model_patient MP
                WHERE registered_training_id = 0
                    AND requested_training_id = {$training_id}
                    AND (deleted = 0)
                ORDER BY RAND()
                LIMIT {$available}
            ";

            $model_patients = $this->DataModelPatient->getConnection()->execute($query_model_patients)->fetchAll('assoc');

            foreach($model_patients as $model)
            {
                $ent_training =  $this->DataModelPatient->find()
                    ->where(['DataModelPatient.id' => $model['id'],'DataModelPatient.deleted' => 0])
                    ->first();

                if (!empty($ent_training)) {                    
                    
                    if($ent_training->registered_training_id == 0 && $ent_training->status != 'assigned'){
                        $this->send_email_gfe($ent_training->email);
                    }
                    $ent_training->registered_training_id = $training_id;
                    $ent_training->status = 'assigned';
                    $ent_training->notification = 1;
                                            
                    $this->DataModelPatient->save($ent_training);
                }                           
                
                $fields = ['DataModelPatient.name','DataModelPatient.mname','DataModelPatient.lname','DataModelPatient.email', 
                            'Assigned.scheduled', 'Assigned.level', 'Assigned.city', 'Assigned.zip', 'Assigned.state_id', 'Assigned.address'];
                $fields['state_abv'] = "(SELECT name from cat_states CS WHERE CS.id = Assigned.state_id LIMIT 1)"; 
                $_where = ['DataModelPatient.id' => $ent_training->id, 'DataModelPatient.deleted' => 0];  
    
                $ent_data_model = 
                    $this->DataModelPatient
                    ->find()
                    ->select($fields)
                    ->join([                        
                        'Assigned' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'Assigned.id = DataModelPatient.registered_training_id'],
                    ])
                    ->where($_where)
                    ->order(['DataModelPatient.created' => 'DESC'])->first();
                $name = $ent_data_model->mname == '' ? $ent_data_model->name : $ent_data_model->name . ' ' . $ent_data_model->mname;
                $training_date = $ent_data_model['Assigned']['scheduled'] == NULL ? '' : date('m/d/Y',strtotime($ent_data_model['Assigned']['scheduled']));
                $training_time = $ent_data_model['Assigned']['scheduled'] == NULL ? '' : date('H:i A',strtotime($ent_data_model['Assigned']['scheduled']));;
                $location      = $ent_data_model['Assigned']['address'].' '.$ent_data_model['Assigned']['city'] . ', ' . $ent_data_model['state_abv'] . ' ' . $ent_data_model['Assigned']['zip'];  ;
                $email = $ent_data_model->email;    
                $body = "
                    <b style='text-align: left; font-size: 17px;'>Hello ".$name.",</b><br>
                    <p>Thank you for applying to be a model with MySpaLive on ".$training_date." at ".$location.".</p><br>
                    <p>You are NOT confirmed until you complete your Telehealth Medical Exam via the MySpaLive App.</p><br>
                    <p>
                        Please note that you are signing up to come to our training at the designated time and location listed above to get 20 free units of Botox. 
                        An injector will NOT be coming to your home to provide treatment. 
                    </p>
                    
                    <br>
    
                    <div>
                        Important things to remember:
                        <ul>
                            <li>Models get 20 Free units. Anything after that will be $5 per unit.</li>
                            <li>This treatment can only be done every three months.</li>
                            <li>The Exam is $19.50 for models when you use the code GFE50OFF at checkout, valid for one year.</li>
                            <li>
                                To complete the Exam, you must sign up as a patient, request treatment and then begin a medical exam. 
                                Remember, you must speak to an actual doctor over a Zoom call to get the treatment.
                            </li>
                        </ul>
                    </div>                                                
                    <br><br>
                ";
                
                $this->send_email_patient_model_assigned($email, $body);
            }
        }
    }

    private function send_email_gfe($email){

        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $email,
            'subject' => 'Good Faith Exam',
            'html'    => 'Go to your app, open the menu, and go to "My Online Medical Exams" to have your GFE before the class.',
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

    private function send_email_patient_model_assigned($email, $body){
        $str_message = '
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

                                '.$body.'
                                
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
        </html>
        ';

        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $email,
            'subject' => 'Your patient model application has been received',
            'html'    => $str_message,
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