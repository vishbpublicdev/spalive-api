<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;

class ReserveCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
        $this->loadModel('SpaLiveV1.DataConsultation');
        sleep(180);
        $consultation_uid = $args->getArguments()[0];

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.assistance_id','DataConsultation.patient_id'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();

        if ($ent_consultation->assistance_id == 0) {
            $this->DataConsultation->updateAll(
                [
                    'reserve_examiner_id'   => 0
                ],
                ['id' =>  $ent_consultation->id]
            );
        }

		$this->success();
    }

}