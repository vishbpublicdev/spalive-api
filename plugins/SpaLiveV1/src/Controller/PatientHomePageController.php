<?php 
	declare(strict_types=1);

	namespace SpaLiveV1\Controller;
	use App\Controller\AppPluginController;

	class PatientHomePageController extends AppPluginController {

		public function initialize() : void {
	        parent::initialize();
			$this->loadModel('SpaLiveV1.SysUsers');
			$this->loadModel('SpaLiveV1.DataPatientsHomePage');
			$this->loadModel('SpaLiveV1.DataPatientsHomePageChildren');
			$this->loadModel('SpaLiveV1.AppToken');
	    }

	    public function get_home_content() {

	    	$aux_array_data = [];

	        $entity = $this->DataPatientsHomePage->find()->select(['DataPatientsHomePage.parent'])->where(['DataPatientsHomePage.deleted' => 0])->group("parent");

	        if(!empty($entity)){
	            foreach($entity as $row) {

	            	$children = [];

	            	$entity_children = $this->DataPatientsHomePage->find()->select(['DataPatientsHomePage.id', 'DataPatientsHomePage.type', 'DataPatientsHomePage.title', 'DataPatientsHomePage.component_action', 'DataPatientsHomePage.description', 'DataPatientsHomePage.position'])
	            	->where(['DataPatientsHomePage.parent' => $row->parent, 'DataPatientsHomePage.deleted' => 0])->order(['DataPatientsHomePage.position ASC']);

	            	if(!empty($entity_children)){
			            foreach($entity_children as $row_children) {

			            	$sub_children = [];
			                if($row_children->type=="Background Image"||$row_children->type=="Image"||$row_children->type=="Text with Image and Description"||$row_children->type=="Carousel of Images"||$row_children->type=="Carousel of Images and Steps"){
			                    $fields = ['DataPatientsHomePageChildren.id','DataPatientsHomePageChildren.type','DataPatientsHomePageChildren.file_or_action','DataPatientsHomePageChildren.title'];
			                    $fields['name'] = "(SELECT F.name FROM _files F WHERE F.id = DataPatientsHomePageChildren.file_or_action)";

			                    $_where_children = ['DataPatientsHomePageChildren.parent_id' => $row_children->id, 'DataPatientsHomePageChildren.deleted' => 0];
			                    $entity_sub_children = $this->DataPatientsHomePageChildren->find()->select($fields)->where($_where_children)->all();

			                    if(!empty($entity_sub_children)){
			                        foreach($entity_sub_children as $row_sub_children) {
			                            $sub_children[] = array(
			                                'type'              => $row_sub_children->type,
			                                'title'             => $row_sub_children->title,
			                                'name'              => $row_sub_children->name,
			                                'file_or_action'    => $row_sub_children->file_or_action,
			                            );
			                        }
			                    }
			                }else 
			                if($row_children->type=="Menu"){
			                	$fields = ['DataPatientsHomePageChildren.id','DataPatientsHomePageChildren.type','DataPatientsHomePageChildren.title',
                                'DataPatientsHomePageChildren.file_or_action'];

			                    $_where_children = ['DataPatientsHomePageChildren.parent_id' => $row_children->id, 'DataPatientsHomePageChildren.deleted' => 0];
			                    $entity_sub_children = $this->DataPatientsHomePageChildren->find()->select($fields)->where($_where_children)->all();

			                    if(!empty($entity_sub_children)){
			                        foreach($entity_sub_children as $row_sub_children) {
			                            $sub_children[] = array(
			                                'type'              => $row_sub_children->type,
			                                'title'             => $row_sub_children->title,
			                                'name'              => "",
			                                'file_or_action'    => $row_sub_children->file_or_action,
			                            );
			                        }
			                    }
			                }

			            	$children[] = array(
			                    'id'			   => $row_children->id,
			                    'type'      	   => $row_children->type,
			                    'title' 	       => $row_children->title,
			                    'componentAction'  => $row_children->component_action,
			                    'description'  	   => $row_children->description,
			                    'children'         => $sub_children,
			                );
			            }
			        }

	                $aux_array_data[] = array(
	                    'parent'		=> $row->parent,
	                    'children'      => $children
	                );
	            }
	        }


	        $array_data = [3];

	        foreach($aux_array_data as $aux) {
	        	if($aux["parent"]=='header'){
	        		$array_data[0] = $aux;
	        	}else if($aux["parent"]=='body'){
	        		$array_data[1] = $aux;
	        	}else if($aux["parent"]=='footer'){
	        		$array_data[2] = $aux;
	        	}
	        }

	        $this->set('pageContent', $array_data);
	        $this->set('get_in_touch', array('email' => 'patientrelations@myspalive.com', 'phone' => '4302054192', 'phone_label' => '430-205-4192'));
			$this->set('offer_patients', array(
				'text1' => "", 
				//'text1' => "Get $50 Off\nYour First Treatment!",
				'text2' => '',
				'text3' => "$200 minimum treatment cost applies.\nLimited-time offer.\nOnly available for on-demand treatments in Texas.",
			));
	       	$this->success();
	    	
	    }

		public function videocall_web() {
			$token = get('token', '');

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

			$treatment_type = get('treatment_type', '');

			switch ($treatment_type) {
				case 'neurotoxins':
					$this->loadModel('SpaLiveV1.DataConsultation');
					$this->loadModel('SpaLiveV1.DataConsultationAnswers');
					$this->loadModel('SpaLiveV1.CatQuestions');

					$consultation = $this->DataConsultation
					->find()
					->select($this->DataConsultation)
					->where([
						'DataConsultation.deleted' => 0, 
						'DataConsultation.patient_id' => USER_ID
					])
					->order(['DataConsultation.id' => 'DESC'])
					->first();

					$consultation_answers = $this->DataConsultationAnswers
					->find()
					->select($this->DataConsultationAnswers)
					->select($this->CatQuestions)
					->join([
						'table' => 'cat_questions',
						'alias' => 'CatQuestions',
						'type' => 'INNER',
						'conditions' => 'CatQuestions.id = DataConsultationAnswers.question_id'
					])
					->where([
						'DataConsultationAnswers.deleted' => 0, 
						'DataConsultationAnswers.consultation_id' => $consultation->id
					])
					->all();

					if(!empty($consultation)){
						// $this->set('consultation', $consultation);
						// $this->set('consultation_answers', $consultation_answers);
						// return;

						switch ($consultation->status) {
							case 'ONLINE':
								$this->set('consultation', "Wait for the examiner to end the call.");
								$this->success();
								return;
							case 'DONE':
								$this->set('consultation', "The examiner successfully completed the call, wait until you have your certificate.");
								$this->success();
								return;
							case 'CERTIFICATE':
								$this->set('consultation', "Your GFE is ready, return to the app to take a look.");
								$this->success();
								return;
						}

						$data_consultation = array();
						$data_answers = array();
						
						foreach ($consultation_answers as $answer) {
							$data_answers[] = array(
								"details" => $answer->details,
								"title" => $answer['CatQuestions']['name'],
								"id" => $answer->question_id,
								"response" => $answer->response,
								"remove" => 0,
							);
						}

						$data_consultation = array(
							"consultation_id" => $consultation->id,
							"answers" => $data_answers,
							"treatments" => $consultation->treatments,
							"language" => $consultation->language,
						);

						$this->set('consultation', $data_consultation);
						$this->success();
					} else {
						$this->set('consultation', false);
					}
					return;
				
				case 'weight_loss':
					$this->loadModel('SpaLiveV1.DataConsultationOtherServices');

					$consultation = $this->DataConsultationOtherServices
					->find()
					->select($this->DataConsultationOtherServices)
					->where([
						'DataConsultationOtherServices.deleted' => 0, 
						'DataConsultationOtherServices.patient_id' => USER_ID,
						'DataConsultationOtherServices.status NOT IN' => ['COMPLETED'],
					])
					->first();

					if (!empty($consultation)) {
						$this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
						$call = $this->DataOtherServicesCheckIn
						->find()
						->select($this->DataOtherServicesCheckIn)
						->where([
							'DataOtherServicesCheckIn.deleted' => 0, 
							'DataOtherServicesCheckIn.consultation_uid' => $consultation->uid,
							'DataOtherServicesCheckIn.call_type' => 'FIRST CONSULTATION',
						])
						->first();

						if (!empty($call)) {
							$data_consultation = array();

							switch ($call->status) {
								case 'ONLINE':
									$this->set('consultation', "Wait for the examiner to end the call.");
									$this->success();
									return;
								case 'PENDING':
									$this->set('consultation', "The examiner has finished the call and waits in the app.");
									$this->success();
									return;
								case 'COMPLETED':
									$this->set('consultation', "The call has already been completed correctly, return to the app.");
									$this->success();
									return;
							}
							
							$data_consultation = array(
								"uid" => $call->consultation_uid,
								"service_uid" => $consultation->service_uid,
								"call_id" => $call->id,
								"call_type" => $call->call_type,
								"call_number" => $call->call_number,
							);

							$this->set('consultation', $data_consultation);
							$this->success();
						} else {
							$this->set('consultation', false);
						}
					} else {
						$this->set('consultation', false);
					}
					return;
			}
		}
	}

 ?>