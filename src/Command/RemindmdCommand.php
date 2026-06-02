<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

/**
 * Notifies MD users of all treatments pending approval (status DONE, approved PENDING).
 * Run via cron every 2 days: bin/cake remindmd
 */
class RemindmdCommand extends Command
{
    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }

        return $this->mailgunKey;
    }

    public function execute(Arguments $args, ConsoleIo $io)
    {
        $this->loadModel('SpaLiveV1.SysUsersAdmin');
        $this->loadModel('SpaLiveV1.DataTreatment');

        $mailgunKey = $this->getMailgunKey();
        if (empty($mailgunKey)) {
            $io->error('MAILGUN_API_KEY is not configured.');
            return static::CODE_ERROR;
        }

        $doctors = $this->SysUsersAdmin->find()->where(['user_type' => 'DOCTOR', 'deleted' => 0])->all();

        foreach ($doctors as $doctor) {
            $treatments = $this->DataTreatment->find()->where([
                'assigned_doctor' => $doctor->id,
                'deleted' => 0,
                'status' => 'DONE',
                'approved' => 'PENDING',
            ])->count();

            if ($treatments <= 0) {
                continue;
            }

            $label = $treatments === 1 ? 'treatment' : 'treatments';
            $data = [
                'from' => 'MySpaLive <noreply@mg.myspalive.com>',
                'to' => $doctor->username,
                'bcc' => 'francisco@advantedigital.com',
                'subject' => $treatments . ' ' . ucfirst($label) . ' Awaiting Your Review',
                'html' => '<span>You currently have ' . $treatments . ' pending ' . $label
                    . ' awaiting your review. Please access your account at md.myspalive.com to complete your reviews.</span>',
            ];

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

            curl_exec($curl);
            curl_close($curl);
        }

        return static::CODE_SUCCESS;
    }
}
