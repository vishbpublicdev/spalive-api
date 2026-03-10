<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

class FiletrayCommand extends Command{
    
	// protected function buildOptionParser(ConsoleOptionParser $parser) {
	// 	$parser
	// 		->addArgument('tray_uid', [
	// 			'help' => 'You need to specify the tray uid.'
	// 		]);
	
	// 	return $parser;
	// }
    public function execute(Arguments $args, ConsoleIo $io){
		
		// pr('LLEGO');
		// pr($args);
		// exit;
		$this->loadModel('SpaLiveV1.FileTray');
		// $tray_uid = 'aca0afdf-6dc8-4dea-af94-ab8563077838';
		$tray_uid = $args->getArguments()[0];
		// pr($tray_uid); exit;

		$ent_tray = $this->FileTray->find()
            ->where([
				'FileTray.uid'	=> $tray_uid,
				'AND' => [
					['FileTray.status <>' => 'FINISHED'],
					// ['FileTray.status <>' => 'PROCESSING'],
				],
				'FileTray.deleted'	=> 0
			])->first();

		// pr($ent_tray);
		// exit;
    
		if (!empty($ent_tray)) {
			$this->FileTray->updateAll(
				['status' => 'PROCESSING'],
				['uid' => $tray_uid]
			);

			switch ($ent_tray->model) {	
				case 'W9Bulk':	
					$this->get_w9_bulk($ent_tray);	
					break;
				case 'W9BulkPatients':	
					$this->get_w9_bulk_patients($ent_tray);		
					break;
				default:
					$this->FileTray->updateAll(
						['status' => 'ERROR', 'observation' => 'INVALID MODEL'],
						['uid' => $tray_uid]
					);
					pr('INVALID MODEL');
					break;
			}
		} else pr('Invalid tray uid.');
	}

	private function get_w9_bulk($ent_tray) {
		$this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataWN');
        // $token = get('token', '');
		$params = json_decode($ent_tray->params, true);
        $type = $params['type'];
        $date_from = $params['date_from'];
        $date_to = $params['date_to'];
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));

        $arr_types = ['clinic','examiner','injector','gfe+ci'];

		$W9_form = $this->DataWN->find()
			->select([
				'DataWN.id',
				'DataWN.uid',
				'DataWN.user_id',
				'DataWN.name',
				'DataWN.bname',
				'DataWN.address',
				'DataWN.payee',
				'DataWN.fatca',
				'DataWN.cat',
				'DataWN.other',
				'DataWN.tax',
				'DataWN.city',
				'DataWN.account',
				'DataWN.requesters',
				'DataWN.ssn',
				'DataWN.ein',
				'DataWN.sign_id',
				'SysUsers.created',
			])
			->where([
				'SysUsers.type' => $type,
				'SysUsers.deleted' => 0,
				'DATE(SysUsers.created) >=' => $date_from,
				'DATE(SysUsers.created) <=' => $date_to,
			])
			->join([
				'SysUsers' => [
					'table' => 'sys_users',
					// 'type' => 'LEFT',
					'conditions' => 'DataWN.user_id = SysUsers.id'
				]
			])
			->group(['SysUsers.id'])
			->order(['DataWN.id' => 'DESC']);

		if ($W9_form->count() == 0) {
			$html2pdf->writeHTML("
				<page>
					<h1>There's no W9 certificates for " . str_replace('+', '-', $type) . " users.</h1>
				</page>");
			$html2pdf->Output('Empty' . str_replace('+', '-', $type) . 'w9-BULK.pdf');
			exit;
		} else {
			
			// print_r($W9_form);
			// exit;
			$aux = 0;
			$html_bulk = '';
			foreach($W9_form as $i => $W9){
				$str_ssn = '';
				$str_ein = '';

				if ($W9->ssn) {
					$W9->ssn = str_replace('-', '', $W9->ssn);
					// <span style=''>X</span>
					// <span style='position: absolute; left: 5mm;'>X</span>
					// <span style='position: absolute; left: 10mm;'>X</span>
					// <span style='position: absolute; left: 20mm;'>X</span>
					// <span style='position: absolute; left: 25mm;'>X</span>
					// <span style='position: absolute; left: 35mm;'>X</span>
					// <span style='position: absolute; left: 40mm;'>X</span>
					// <span style='position: absolute; left: 45mm;'>X</span>
					// <span style='position: absolute; left: 50mm;'>X</span>
					$arr_ssn_spaces = [0,5,10,20,25,35,40,45,50];
					$arr_ssn = str_split($W9->ssn);
					foreach($arr_ssn as $index => $ssn){
						if ($index == 0) $str_ssn .= "<span style=''>";
						else {
							$spacing = (isset($arr_ssn_spaces[$index]) ? $arr_ssn_spaces[$index] : ($spacing + 5));
							$str_ssn .= "<span style='position: absolute; left: {$spacing}mm;'>";
						}
						
						$str_ssn .= "{$ssn}</span>";
					}
				}

				if ($W9->ein) {
					$W9->ein = str_replace('-', '', $W9->ein);
					// <span style=''>X</span>
					// <span style='position: absolute; left: 5mm;'>X</span>
					// <span style='position: absolute; left: 15mm;'>X</span>
					// <span style='position: absolute; left: 20mm;'>X</span>
					// <span style='position: absolute; left: 25mm;'>X</span>
					// <span style='position: absolute; left: 30mm;'>X</span>
					// <span style='position: absolute; left: 35mm;'>X</span>
					// <span style='position: absolute; left: 40mm;'>X</span>
					// <span style='position: absolute; left: 45mm;'>X</span>
					$arr_ein_spaces = [0,5,15,20,25,30,35,40,45];
					$arr_ein = str_split($W9->ein);

					foreach($arr_ein as $index => $ein){
						if ($index == 0) $str_ein .= "<span style=''>";
						else {
							$spacing = (isset($arr_ein_spaces[$index]) ? $arr_ein_spaces[$index] : ($spacing + 5));
							$str_ein .= "<span style='position: absolute; left: {$spacing}mm;'>";
						}
						
						$str_ein .= "{$ein}</span>";
					}
				}
				// print_r($W9);
				// exit;
				// PÁGINA 1
				$html_bulk .= "
					<page>
						<div style='width: 210mm; height: 295mm; position:relative;'>
							<img style='width:210mm; height: 295mm; position:absolute; z-index: 1;' src='" . env('URL_ASSETS', 'https://api.spalivemd.com/assets/') . "fw9-1.jpg' />
							<div style='width:210mm; height: 295mm; position:absolute; z-index: 2;'>
								<div style='margin-left: 25mm; margin-top: 35.5mm; font-size: 12px;'>
									<div style='position: absolute;left: 190mm;top: 63mm;'>{$W9->payee}</div>
									<div style='position: absolute;left: 175mm;top: 76.5mm;'>{$W9->fatca}</div>
									<div style='position: absolute;left: 135mm;top: 93mm; width: 62mm; height: 14mm; text-align: justify; font-size: 11px;'>
										{$W9->requesters}
									</div>
									<p style='margin: 0;'>" . ($W9->name ? $W9->name : '-') . "</p>
									<div style='margin: 5.5mm 0 0 0; height: 3mm;'>" . ($W9->bname ? $W9->bname : '-') . "</div>
									<div style='margin-top: 11.2mm; margin-left: -2.1mm; position: relative;'>
										<span>" . ($W9->cat == 'INDIVIDUAL' ? 'X' : '') . "</span>
										<span style='position: absolute; left: 39.17mm; top: -0.4mm;'>" . ($W9->cat == 'C' ? 'X' : '') . "</span>
										<span style='position: absolute; left: 63.9mm; top: -0.4mm;'>" . ($W9->cat == 'S' ? 'X' : '') . "</span>
										<span style='position: absolute; left: 88.6mm; top: -0.4mm;'>" . ($W9->cat == 'PARTNERSHIP' ? 'X' : '') . "</span>
										<span style='position: absolute; left: 113.3mm; top: -0.4mm;'>" . ($W9->cat == 'TRUST' ? 'X' : '') . "</span>
									</div>
									<div style='margin-top: 5.5mm; margin-left: -2.1mm; position: relative;'>
										<span>" . ($W9->cat == 'LLC' ? 'X' : '') . "</span>
										<span style='position: absolute; left: 124mm; top: -0.4mm;'>" . ($W9->cat == 'LLC' ? $W9->tax : '') . "</span>
									</div>
									<div style='margin-top: 14mm; margin-left: -2.1mm; position: relative;'>
										<span>" . ($W9->cat == 'OTHER' ? 'X' : '') . "</span>
									</div>
									<p style='margin-top: 4.5mm;'>" . ($W9->address ? $W9->address : '-') . "</p>
									<p style='margin-top: 2.5mm;'>" . ($W9->city ? $W9->city : '-') . "</p>
									<p style='margin-top: 2.5mm;'>" . ($W9->account ? $W9->account : '-') . "</p>
									<div style='top: 128mm; margin-left: 144.5mm; position: absolute;'>
										{$str_ssn}
									</div>
									<div style='top: 146mm; margin-left: 144.5mm; position: absolute;'>
										{$str_ein}
									</div>".

									($W9->sign_id > 0 ?
										"<img src='" . env('URL_API', 'https://api.spalivemd.com/') . "?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&l3n4p=6092482f7ce858.91169218&action=get-file&id={$W9->sign_id}' style='top: 200.5mm;margin-left: 60mm; position: absolute; width: 40mm;'/>"
										: '')

									."<p style='top: 202.5mm;padding-left: 117mm; position: absolute;'>" . date( 'm/d/Y H:i', strtotime($W9->SysUsers['created'])) . "</p>
								</div>
							</div>
						</div>
					</page>";

				// if ($aux == 10) break;
				$aux++;
				
			}
			$html2pdf->writeHTML($html_bulk);
			$html2pdf->Output(TMP . 'reports' . DS . $ent_tray->filename, 'F');

			$this->FileTray->updateAll(
				['status' => 'FINISHED'],
				['id' => $ent_tray->id]
			);

			pr('FINISHED');
			exit;

		}
	}

	private function get_w9_bulk_patients($ent_tray) {
		
		$this->loadModel('SpaLiveV1.SysUsers');
        // $token = get('token', '');
		$params = json_decode($ent_tray->params, true);
        $type = $params['type'];
        $date_from = $params['date_from'];
        $date_to = $params['date_to'];
        $html2pdf = new HTML2PDF('F','A3','en', true, 'UTF-8', array(0,0,0,0));
		
        $arr_types = ['patient'];

        $fields = ['SysUsers.name','SysUsers.mname','SysUsers.lname','SysUsers.created','SysUsers.phone','SysUsers.email'];        
        $fields['comments'] = "(SELECT notes FROM data_users_notes DUN WHERE DUN.user_id = SysUsers.id)";
        $fields['last_exam'] = "(SELECT DC.schedule_date FROM data_consultation DC WHERE DC.patient_id = SysUsers.id AND DC.status = 'CERTIFICATE' ORDER BY DC.schedule_date DESC LIMIT 1)";
        $fields['last_treatment'] = "(SELECT DT.schedule_date FROM data_treatment DT WHERE DT.patient_id = SysUsers.id AND DT.status = 'DONE' ORDER BY DT.schedule_date DESC LIMIT 1)";

        $_where = ['SysUsers.deleted' => 0, 'SysUsers.active' => 1, 'SysUsers.type' => 'patient', 'DATE(SysUsers.created) >=' => $date_from, 'DATE(SysUsers.created) <=' => $date_to];

        $order = ['SysUsers.created' => 'DESC'];

        $W9_form = $this->SysUsers->find()->select($fields)->where($_where)->order($order)->all();
		
		if ($W9_form->count() == 0) {
			$html2pdf->writeHTML("
				<page>
					<h1>There's no W9 certificates for " . str_replace('+', '-', $type) . " users.</h1>
				</page>");
			$html2pdf->Output('Empty' . str_replace('+', '-', $type) . 'w9-BULK-PATIENTS.pdf');
			exit;
		} else {    
			$html_bulk = "
					<page>
						<div style='width: 295mm; height: 210mm; position:relative;'>
							<div style='width:295mm; height: 210mm; position:absolute; z-index: 1;'>
								<div style='margin-left: 5mm; margin-top: 5.5mm; font-size: 12px;'>
									<p style='margin: 0;'><h2>Active Patients</h2></p>
                                    <table>
                                        <tr>
                                            <th style='width:30mm;'><strong>Name</strong></th>
                                            <th style='width:30mm;'><strong>Registration Date</strong></th>
                                            <th style='width:50mm;'><strong>Comments</strong></th>
                                            <th style='width:25mm;'><strong>Phone</strong></th>
                                            <th style='width:30mm;'><strong>Email</strong></th>
                                            <th style='width:30mm;'><strong>Last Exam</strong></th>
                                            <th style='width:30mm;'><strong>Last Treatment</strong></th>
                                        </tr>";

			foreach($W9_form as $i => $W9){
				
				$html_bulk .= "
                                        <tr>
											<td>" . ($W9->name ? $W9->name : '') . ' ' . ($W9->lname ? $W9->lname : '') . "</td>
											<td>" . ($W9->created ? $W9->created : '') . "</td>
											<td style='width:50mm;'>" . ($W9->comments ? $W9->comments : '') . "</td>
											<td>" . ($W9->phone ? $W9->phone : '') . "</td>
											<td>" . ($W9->email ? $W9->email : '') . "</td>
											<td>" . ($W9->last_exam ? $W9->last_exam : '') . "</td>
											<td>" . ($W9->last_treatment ? $W9->last_treatment : '') . "</td>
                                        </tr>";
			}

            $html_bulk .= "
                                    </table>
								</div>
							</div>
						</div>
					</page>";
			// print_r($html_bulk); exit;
			$html2pdf->writeHTML($html_bulk);
			$html2pdf->Output(TMP . 'reports' . DS . $ent_tray->filename, 'F');

			$this->FileTray->updateAll(
				['status' => 'FINISHED'],
				['id' => $ent_tray->id]
			);

			pr('FINISHED');
			exit;
		} 
	}
}