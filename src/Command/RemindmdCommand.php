<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text;

class RemindmdCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
 
        $isDev = env('IS_DEV', false);
        
        $this->loadModel('SpaLiveV1.SysUsersAdmin');
        $this->loadModel('SpaLiveV1.DataTreatment');
        $doctors = $this->SysUsersAdmin->find()->where(['user_type' => 'DOCTOR', 'deleted' => 0])->all();

        // Obtener el lunes de la semana pasada
        $lunes_pasado = date("Y-m-d 00:00:00", strtotime("last week monday"));

        // Obtener el domingo de la semana pasada
        $domingo_pasado = date("Y-m-d 23:59:59", strtotime("last week sunday"));

        foreach ($doctors as $doctor) {
            $treatments = $this->DataTreatment->find()->where(['assigned_doctor' => $doctor->id, 'deleted' => 0, 'status' => 'DONE', 'approved' => 'PENDING', 'schedule_date BETWEEN "' . $lunes_pasado . '" AND "' . $domingo_pasado . '"'])->count();

            if ($treatments > 0) {
                $data = array(
                    'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                    //'to'      => 'francisco@advantedigital.com',
                    'to'      => $doctor->username,
                    'bcc'     => 'francisco@advantedigital.com',
                    'subject' => $treatments . ' Treatments Awaiting Your Review - Week of ' . date('m/d/Y', strtotime($lunes_pasado)) . ' to ' . date('m/d/Y', strtotime($domingo_pasado)),
                    'html'    => '<span>You currently have ' . $treatments . ' pending treatments awaiting your review. Please access your account at md.myspalive.com to complete your reviews.</span>',
                );
        
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
                curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($curl, CURLOPT_POST, true); 
                curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        
                $result = curl_exec($curl);
        
                curl_close($curl);
            }
        }

    }
}