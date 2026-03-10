<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use PHPUnit\Framework\Constraint\Count;

use SpaLiveV1\Controller\MainController;

class TreatmentsCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
 
        $isDev = env('IS_DEV', false);

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataOpenTreatmentNotifications');
        $this->loadModel('SpaLiveV1.DataTreatmentNotifications');

        $data = $this->DataOpenTreatmentNotifications->find()->distinct(['DataOpenTreatmentNotifications.treatment_id'])->where(['DataOpenTreatmentNotifications.deleted' => 0, 'DataOpenTreatmentNotifications.sent' => 0])->all();
        $Main = new MainController();

        if(Count($data) > 0) {
            foreach($data as $row) {
                $fields = [
                    'DataTreatment.id',
                    'DataTreatment.uid',
                    'DataTreatment.assistance_id',
                    'DataTreatment.patient_id',
                    'DataTreatment.treatments',
                    'DataTreatment.schedule_date',
                    'DataTreatment.status',
                    'DataTreatment.address',
                    'DataTreatment.zip',
                    'DataTreatment.city',
                    'DataTreatment.suite',
                    'DataTreatment.latitude',
                    'DataTreatment.longitude',
                    'State.name',
                    'DataTreatment.tip'
                ];
                $treatment = $this->DataTreatment->find()->select($fields)->join([
                    'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                ])->where(['DataTreatment.id' => $row->treatment_id])->first();

                if($treatment->assistance_id > 0){
                    $this->DataOpenTreatmentNotifications->updateAll(['sent' => 1], ['treatment_id' => $row->treatment_id]);
                }else{
                    $notif = $this->DataOpenTreatmentNotifications->find()->where(['DataOpenTreatmentNotifications.deleted' => 0, 'DataOpenTreatmentNotifications.sent' => 0, 'DataOpenTreatmentNotifications.treatment_id' => $row->treatment_id])->first();
                    
                    // LLenar variable de address
                    $sstr_address = $treatment->address . ', ' . $treatment->city . ', ' . $treatment['State']['name'] . ' ' . $treatment->zip;
                    if (!empty($certTreatment->suite)) {
                        $sstr_address = $treatment->address . ', ' . $treatment->suite . ', ' . $treatment->city . ', ' . $treatment['State']['name'] . ' ' . $treatment->zip;
                    }

                    $constants = [
                        '[SCHEDULE_PATIENT]' => $treatment['schedule_date']->i18nFormat('MM-dd-yyyy hh:mm a'),
                        '[ADDRESS_PATIENT]' => $sstr_address
                    ];

                    $dn = $this->DataTreatmentNotifications->find()->where(['treatment_id' => $treatment->id])->first();

                    if(!empty($dn)){
                        $this->DataTreatmentNotifications->updateAll(['id_invitations' => $dn->id_invitations . ',' . $notif->injectors_ids],['treatment_id' => $treatment->id]);
                    }else{
                        $arr_save = array(
                            'treatment_id' => $treatment->id,
                            'id_invitations' => $notif->injectors_ids,
                            'created' => date('Y-m-d H:i:s'),
                        );
                        $ent_not = $this->DataTreatmentNotifications->newEntity($arr_save);
                        $this->DataTreatmentNotifications->save($ent_not);
                    }

                    // ******* users_array debe sacarse de la tabla
                    $Main->notify_devices('TREATMENT_AVAILABLE', explode(',', $notif->injectors_ids), true, true, true, array(), '', $constants, true);
                    
                    $notif->sent = 1;
                    $this->DataOpenTreatmentNotifications->save($notif);
                }
            }
        }
    }
}