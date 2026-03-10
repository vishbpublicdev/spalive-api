<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text;
use Cake\I18n\FrozenTime;

require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

use SpaLiveV1\Controller\PaymentsController;
use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\GhlController;
use SpaLiveV1\Controller\LoginController;
/**
 * DeferredPaymentsCommand
 * 
 * Comando para procesar pagos diferidos/programados
 * Ejecuta pagos programados para el día actual usando Stripe
 * 
 * IMPORTANTE: Si un pago falla, NO se reintenta automáticamente.
 * Se envía un correo al usuario para que actualice su método de pago.
 * 
 * Uso:
 * bin/cake deferred_payments              -> Procesa todos los pagos del día
 * bin/cake deferred_payments [user_uid]   -> Procesa pagos de un usuario específico
 */
class DeferredPaymentsCommand extends Command {
    
    /**
     * Modelos utilizados
     */
    protected $DataDeferredPayments;
    protected $DataDeferredPaymentsLog;
    protected $SysUsers;
    private $full_comission = 10000;
    private $half_comission = 5000;
    
    /**
     * Configuración de Stripe
     */
    protected $stripeSecretKey;
    
    /**
     * ConsoleIo para logging
     */
    protected $io;

    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }
    
    /**
     * Inicializa el comando
     */
    public function initialize(): void {
        parent::initialize();
        
        // Cargar modelos
        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        $this->loadModel('SpaLiveV1.DataDeferredPaymentsLog');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPayment');
        
        // Configurar Stripe
        $this->stripeSecretKey = Configure::read('App.stripe_secret_key');
        \Stripe\Stripe::setApiKey($this->stripeSecretKey);
    }
    
    /**
     * Función principal de ejecución
     */
    public function execute(Arguments $args, ConsoleIo $io) {
        $this->io = $io;
        $arr_arguments = $args->getArguments();
        
        $io->out('======================================');
        $io->out('Deferred Payments Command - Iniciando');
        $io->out('Fecha: ' . date('Y-m-d H:i:s'));
        $io->out('======================================');
        
        // Determinar qué acción ejecutar
        if (!empty($arr_arguments)) {
            // Procesar pagos de un usuario específico
            $this->processDailyPayments($arr_arguments[0]);
        } else {
            // Procesar todos los pagos del día
            $this->processDailyPayments();
        }
        
        $io->out('======================================');
        $io->out('Proceso finalizado');
        $io->out('======================================');
        
        return static::CODE_SUCCESS;
    }
    
    /**
     * Procesa los pagos programados para el día actual
     * 
     * @param string|null $user_uid UID del usuario para filtrar (opcional)
     */
    private function processDailyPayments($user_uid = null) {
        $this->io->out("\nBuscando pagos programados para hoy...");
        $is_dev = env('IS_DEV', false);
        // Construir condiciones de búsqueda
        if ($is_dev) {
            $where = [
                'DataDeferredPayments.status' => 'PENDING',
                'DataDeferredPayments.deleted' => 0,
                'DataDeferredPayments.source' => 'stripe',
            ];
        } else {
            $where = [
                'DataDeferredPayments.scheduled_date <=' => date('Y-m-d'),
                'DataDeferredPayments.status' => 'PENDING',
                'DataDeferredPayments.deleted' => 0,
                'DataDeferredPayments.source' => 'stripe',
            ];
        }
        
        // Filtrar por usuario si se especifica
        if ($user_uid) {
            $user = $this->SysUsers->find()
                ->where(['SysUsers.uid' => $user_uid])
                ->first();
                
            if ($user) {
                $where['DataDeferredPayments.user_id'] = $user->id;
                $this->io->info("Filtrando por usuario: {$user->name} {$user->lname} (ID: {$user->id})");
            } else {
                $this->io->error("Usuario no encontrado con UID: {$user_uid}");
                return;
            }
        }
        
        // Buscar pagos pendientes
        $payments = $this->DataDeferredPayments->find()
            ->select([
                'DataDeferredPayments.id',
                'DataDeferredPayments.uid',
                'DataDeferredPayments.user_id',
                'DataDeferredPayments.customer_id',
                'DataDeferredPayments.payment_method',
                'DataDeferredPayments.amount',
                'DataDeferredPayments.currency',
                'DataDeferredPayments.description',
                'DataDeferredPayments.type',
                'DataDeferredPayments.reference_id',
                'DataDeferredPayments.reference_type',
                'DataDeferredPayments.scheduled_date',
                'DataDeferredPayments.metadata',
                'User.id',
                'User.name',
                'User.lname',
                'User.email',
                'User.phone'
            ])
            ->join([
                'User' => [
                    'table' => 'sys_users',
                    'type' => 'INNER',
                    'conditions' => 'User.id = DataDeferredPayments.user_id'
                ]
            ])
            ->where($where)
            ->order(['DataDeferredPayments.scheduled_date' => 'ASC'])
            ->all();
        
        $count = $payments->count();
        $this->io->success("Encontrados {$count} pagos pendientes");
        
        if ($count === 0) {
            return;
        }
        
        // Procesar cada pago
        $processed = 0;
        $successful = 0;
        $failed = 0;
        
        foreach ($payments as $payment) {
            $processed++;
            $this->io->out("\n--- Pago {$processed}/{$count} ---");
            $this->io->out("ID: {$payment->id} | UID: {$payment->uid}");
            $this->io->out("Usuario: " . $payment['User']['name'] . " " . $payment['User']['lname']);
            $this->io->out("Email: " . $payment['User']['email']);
            $this->io->out("Monto: $" . number_format($payment->amount / 100, 2));
            $this->io->out("Descripción: {$payment->description}");
            
            // Marcar como procesando
            $this->updatePaymentStatus($payment->id, 'PROCESSING');
            
            // Ejecutar el pago
            $result = $this->executePayment($payment);
            
            if ($result['success']) {
                $successful++;
                $this->io->success("✓ Pago procesado exitosamente");
                
                // Actualizar el pago como completado
                $this->updatePaymentAsCompleted($payment->id, $result);
                
                // Enviar correo de confirmación
                $this->sendSuccessEmail($payment, $result);
                
                // Send SMS to Jenna (default sales rep) when payment is successful
                $this->sendSuccessSMSToJenna($payment);
                
            } else {
                $failed++;
                $this->io->error("✗ Error al procesar el pago: {$result['error']}");
                
                // Manejar el error (reintento o marcado como fallido)
                $this->handlePaymentFailure($payment, $result);
                
                // Enviar correo de error
                $this->sendErrorEmail($payment, $result);
            }
            
            // Log del intento
            $this->logPaymentAttempt($payment->id, 1, $result);
        }
        
        // Resumen
        $this->io->out("\n=== RESUMEN ===");
        $this->io->out("Total procesados: {$processed}");
        $this->io->success("Exitosos: {$successful}");
        if ($failed > 0) {
            $this->io->error("Fallidos: {$failed}");
        }
    }
    
    /**
     * Ejecuta un pago usando Stripe
     * 
     * @param object $payment Entidad del pago
     * @return array Resultado del pago ['success' => bool, 'payment_intent_id' => string, ...]
     */
    private function executePayment($payment) {
        $result = [
            'success' => false,
            'payment_intent_id' => null,
            'charge_id' => null,
            'receipt_url' => null,
            'error' => null,
            'stripe_response' => null,
            'amount' => $payment->amount
        ];
        
        try {
            // Crear PaymentIntent en Stripe
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'customer' => $payment->customer_id,
                'payment_method' => $payment->payment_method,
                'off_session' => true,
                'confirm' => true,
                'description' => $payment->description,
                'metadata' => [
                    'deferred_payment_id' => $payment->id,
                    'deferred_payment_uid' => $payment->uid,
                    'user_id' => $payment->user_id,
                    'type' => $payment->type,
                    'reference_id' => $payment->reference_id,
                    'reference_type' => $payment->reference_type
                ]
            ]);
            
            // Extraer información del resultado
            if (isset($paymentIntent->charges->data[0])) {
                $charge = $paymentIntent->charges->data[0];
                $result['charge_id'] = $charge->id;
                $result['receipt_url'] = $charge->receipt_url ?? null;
            }
            
            $result['success'] = true;
            $result['payment_intent_id'] = $paymentIntent->id;
            $result['stripe_response'] = json_encode($paymentIntent);
            
        } catch (\Stripe\Exception\CardException $e) {
            // Tarjeta declinada
            $result['error'] = $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\RateLimitException $e) {
            // Demasiadas requests
            $result['error'] = 'Rate limit: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Parámetros inválidos
            $result['error'] = 'Invalid request: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Error de autenticación
            $result['error'] = 'Authentication error: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            // Error de conexión
            $result['error'] = 'Connection error: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Error genérico de Stripe
            $result['error'] = 'Stripe error: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Exception $e) {
            // Cualquier otro error
            $result['error'] = 'Unexpected error: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Actualiza el status de un pago
     */
    private function updatePaymentStatus($payment_id, $status) {
        $this->DataDeferredPayments->updateAll(
            ['status' => $status],
            ['id' => $payment_id]
        );
    }
    
    /**
     * Actualiza un pago como completado
     */
    private function updatePaymentAsCompleted($payment_id, $result) {
        $this->DataDeferredPayments->updateAll([
            'status' => 'COMPLETED',
            'executed_date' => date('Y-m-d H:i:s'),
            'payment_intent_id' => $result['payment_intent_id'],
            'charge_id' => $result['charge_id'],
            'receipt_url' => $result['receipt_url'],
            'error_message' => null,
            'modified' => date('Y-m-d H:i:s')
        ], [
            'id' => $payment_id
        ]);

        $this->DataPayment->updateAll([
            'intent' => $result['payment_intent_id'],
            'payment' => $result['charge_id'],
            'receipt' => $result['receipt_url'],
            'total' => $result['amount'],
            'created' => date('Y-m-d H:i:s')
        ], [
            'deferred_payment_id' => $payment_id
        ]);

        $payment = $this->DataPayment->find()
        ->select([
            'DataPayment.id',
            'DataPayment.uid',
            'DataPayment.type',
            'DataPayment.total',
            'user_id'=>'User.id',
            'user_name'=>'User.name',
            'user_lname'=>'User.lname',
            'user_email'=>'User.email',
            'user_phone'=>'User.phone',
        ])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataPayment.id_from']
        ])
        ->where(['DataPayment.deferred_payment_id' => $payment_id])->first();
        
        if(empty($payment)){
            $this->io->error("✗ No se encontró el pago en data_payment para deferred_payment_id: {$payment_id}");
            return;
        }
        
        $course = $payment->type;
        $course_amount = $payment->total;
        $total_amount = $payment->total;
        $payment_uid = $payment->uid;
        $type_string = $payment->type;
        $user_id = $payment->user_id;
        $user_name = $payment->user_name;
        $user_lname = $payment->user_lname;
        $user_email = $payment->user_email;
        $user_phone = $payment->user_phone;

        #region Pay comission to sales representative
        // Default sales representative ID (fallback when no rep is assigned)
        $default_rep_id = 6101;
        
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        $this->loadModel('SpaLiveV1.DataSalesRepresentative');
        $this->loadModel('SpaLiveV1.SysUsers');

        $Payments = new PaymentsController();
        $Main = new MainController();
        $Login = new LoginController();

        if($course == 'BASIC COURSE'){
            $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
            ])->where(['DataAssignedToRegister.user_id' => $user_id,'DataAssignedToRegister.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();

            if(empty($assignedRep)){
                $Login = new LoginController();
                $Login->assignRep(true);
                $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                    'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                ])->where(['DataAssignedToRegister.user_id' => $user_id,'DataAssignedToRegister.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();
            }
        } else{
            $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
            ])->where(['DataAssignedToRegister.user_id' => $user_id,'DataAssignedToRegister.deleted' => 0, 'DSR.team' => 'INSIDE'])->last();

            if(empty($assignedRep)){
                $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                    'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                ])->where(['DataAssignedToRegister.user_id' => $user_id,'DataAssignedToRegister.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();
            }
        }

        // Fallback: If no assigned rep found, use default rep (6101)
        if (empty($assignedRep)) {
            $this->io->warning("⚠ No se encontró representante asignado para user_id: {$user_id}. Usando representante por defecto (ID: {$default_rep_id})");
            $default_user = $this->SysUsers->find()->where(['id' => $default_rep_id, 'deleted' => 0])->first();
            if(!empty($default_user)){
                $assignedRep = ['User' => ['id' => $default_rep_id, 'email' => $default_user->email]];
            } else {
                $this->io->error("✗ No se encontró el representante por defecto en sys_users (ID: {$default_rep_id}). Usando valores por defecto para asegurar que se genere la comisión.");
                // Use default values to ensure commission is always generated
                $assignedRep = ['User' => ['id' => $default_rep_id, 'email' => '']];
            }
        }

        $amount_comission = 0;
        $description_comission = '';
        // Use the payment we already found instead of searching again
        $pay = $payment;
        
        // Ensure $pay is never empty - if payment was found earlier, this should always be set
        if(empty($pay)){
            $this->io->error("✗ Error crítico: El objeto de pago está vacío. No se puede generar la comisión.");
            return;
        }

        $representative = $this->DataSalesRepresentative->find()->where(['DataSalesRepresentative.user_id' => $assignedRep['User']['id'], 'DataSalesRepresentative.deleted' => 0])->first();
        
        // Fallback: If no representative found in data_sales_representative, use default rep
        if(empty($representative)){
            $this->io->warning("⚠ No se encontró representante en data_sales_representative para user_id: {$assignedRep['User']['id']}. Usando representante por defecto (ID: {$default_rep_id})");
            $default_rep = $this->DataSalesRepresentative->find()->where(['DataSalesRepresentative.user_id' => $default_rep_id, 'DataSalesRepresentative.deleted' => 0])->first();
            if(!empty($default_rep)){
                $representative = $default_rep;
                $assignedRep['User']['id'] = $default_rep_id;
                $default_user = $this->SysUsers->find()->where(['id' => $default_rep_id, 'deleted' => 0])->first();
                if(!empty($default_user)){
                    $assignedRep['User']['email'] = $default_user->email;
                }
            } else {
                $this->io->error("✗ No se encontró el representante por defecto en data_sales_representative (ID: {$default_rep_id}). Usando valores por defecto para asegurar que se genere la comisión.");
                // Create a dummy representative object with default values to ensure commission is always generated
                $representative = (object)[
                    'user_id' => $default_rep_id,
                    'rank' => 'SENIOR',
                    'team' => 'OUTSIDE'
                ];
                $assignedRep['User']['id'] = $default_rep_id;
            }
        }

            if($course == 'BASIC COURSE'){ 
                $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . $user_name . ' ' . $user_lname . ', ' . $this->formatPhoneNumber($user_phone) . ', has completed the basic training purchase for $' . $total_amount / 100, $Main);
                $msg = 'MySpaLive - ' . $user_name . ' ' . $user_lname . ', has completed the basic training purchase for $' . $total_amount / 100;
                $Main->send_email_after_register($assignedRep['User']['email'],'Basic training purchase',$msg);//($to, $subject, $body) 
                
                #region Pay Commision invitation injector
                $this->loadModel('SpaLiveV1.DataNetworkInvitations');

                $existUser = $this->DataNetworkInvitations->find()->where(['DataNetworkInvitations.email LIKE' => strtolower($user_email)])->first();

                if(!empty($existUser)){
                    $invite_user = $this->SysUsers->find()->where(['id' => $existUser->parent_id, 'deleted' => 0, 'active' => 1])->first();

                    if(!empty($invite_user)){
                        $array_save_invitation = array(
                            'uid' => Text::uuid(),
                            'payment_id' => $pay->id,
                            'amount' => 5000,
                            'user_id' => $existUser->parent_id,
                            'payment_uid' => '',
                            'description' => 'PAY INVITATION',
                            'payload' => '',
                            'deleted' => 1,
                            'created' => date('Y-m-d H:i:s'),
                            'createdby' => $user_id,
                        );
        
                        $c_entity_invitation = $this->DataSalesRepresentativePayments->newEntity($array_save_invitation);
                        $this->DataSalesRepresentativePayments->save($c_entity_invitation);
                        $this->full_comission = 5000;
                        $service = 'Neurtoxins';
                        // $this->send_email_sales_team_member(USER_ID, $service, 'MD', 'Full', 7500, $assignedRep);
                    }
                }
                #endregion
                $value_discount = $Payments->getParams('discount_amount', 0);
                /* if($value_discount <= 20000){ // 795 - 200 = 595
                    $amount_comission = $this->full_comission;
                } else */ 
                if ($value_discount <= 30000){ // 795 - 300 = 495 // 795 - 201 = 594
                    // $amount_comission = $this->half_comission;
                    $amount_comission = $this->full_comission;
                } else if($value_discount >= 30100){ // 795 - 300 = 494
                    $amount_comission = 0;
                }
                
                $description_comission = 'SALES TEAM BASIC';
                if($representative->rank == 'JUNIOR' && !empty($existUser)){ // Si el representante es JUNIOR y hay invitacion entonces solo cambiamos el monto de la comision a $50
                    $amount_comission = $amount_comission == 0 ? 0 : 5000;
                }else if($representative->rank == 'JUNIOR' && empty($existUser)){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                    $amount_comission = $amount_comission == 0 ? 0 : 5000;
                    $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                        'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                    ])->where(['DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                    if(!empty($pay)){
                        $array_save_comission = array(
                            'uid' => Text::uuid(),
                            'payment_id' => $pay->id,
                            'amount' => $amount_comission,
                            'user_id' => $senior_rep->user_id,
                            'payment_uid' => '',
                            'description' => $description_comission,
                            'payload' => '',
                            'deleted' => 1,
                            'created' => date('Y-m-d H:i:s'),
                            'createdby' => defined('USER_ID') ? $user_id : 0,
                        );
                        $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                        $this->DataSalesRepresentativePayments->save($c_entity_comission);
                        $service = 'Training';
                        $Payments->send_email_team_member_courses($user_id, $service, $type_string, $amount_comission, $senior_rep);
                    }
                } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                    $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
                    $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                        'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                    ])->where(['DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                    if(!empty($pay)){
                        $array_save_comission = array(
                            'uid' => Text::uuid(),
                            'payment_id' => $pay->id,
                            'amount' => $amount_comission_senior,
                            'user_id' => $senior_rep->user_id,
                            'payment_uid' => '',
                            'description' => $description_comission,
                            'payload' => '',
                            'deleted' => 1,
                            'created' => date('Y-m-d H:i:s'),
                            'createdby' => $user_id,
                        );
                        $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                        $this->DataSalesRepresentativePayments->save($c_entity_comission);
                        $service = 'Training';
                        $Payments->send_email_team_member_courses($user_id, $service, $type_string, $amount_comission_senior, $senior_rep);
                    }
                } else if($representative->rank == 'SENIOR'){ // If the representative is SENIOR, set commission amount based on team
                    if($representative->team == 'OUTSIDE'){
                        $amount_comission = $amount_comission == 0 ? 0 : 10000; // SENIOR OUTSIDE receives $100
                    }
                    // SENIOR INSIDE keeps the calculated amount_comission
                    // Note: Commission will be saved at the end of the function (line 738)
                }

                if(!env('IS_DEV', false)){
                    $Ghl = new GhlController();
                    $array_ghl = array(
                        'email' => $user_email,
                        'name' => $user_name,
                        'lname' => $user_lname,
                        'phone' => $user_phone,
                        'costo' => 0,
                        'column' => 'Purchased basic',
                    );
                    $contactId = $Ghl->updateOpportunityTags($array_ghl);
                    $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Purchased basic');
                    $this->set('tag', $tag);
                }

            }else if($course == 'ADVANCED COURSE'){
                $msg = 'MySpaLive - ' . $user_name . ' ' . $user_lname . ', has completed the advanced training purchase for $' . $total_amount / 100;
                $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . $user_name . ' ' . $user_lname . ', ' . $this->formatPhoneNumber($user_phone) . ', has completed the advanced training purchase for $' . $total_amount / 100, $Main);
                $Main->send_email_after_register($assignedRep['User']['email'],'Advanced training purchase',$msg);

                $value_discount = $Payments->getParams('discount_amount', 0);
                /* if($value_discount <= 20000){ // 795 - 200 = 595
                    $amount_comission = $this->full_comission;
                } else */ 
                if ($value_discount <= 30000){ // 795 - 300 = 495 // 795 - 201 = 594
                    // $amount_comission = $this->half_comission;
                    $amount_comission = $this->full_comission;
                } else if($value_discount >= 30100){ // 795 - 300 = 494
                    $amount_comission = 0;
                }

                $description_comission = 'SALES TEAM ADVANCED';

                if($representative->team == 'INSIDE'){ // Si el representante es INSIDE entonces pagamos con normalidad la comision y le generamos senior otra comision de $50
                    if($representative->rank == 'JUNIOR' ){ // Si el representante es JUNIOR se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission = $amount_comission == 0 ? 0 : 5000;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay)){
                            $array_save_comission = array(
                                'uid' => Text::uuid(),
                                'payment_id' => $pay->id,
                                'amount' => $amount_comission,
                                'user_id' => $senior_rep->user_id,
                                'payment_uid' => '',
                                'description' => $description_comission,
                                'payload' => '',
                                'deleted' => 1,
                                'created' => date('Y-m-d H:i:s'),
                                'createdby' => $user_id,
                            );
            
                            $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                            $this->DataSalesRepresentativePayments->save($c_entity_comission);
                            $service = 'Training';
                            $Payments->send_email_team_member_courses($user_id, $service, $type_string, $amount_comission, $senior_rep);
                        }
                    } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay)){
                            $array_save_comission = array(
                                'uid' => Text::uuid(),
                                'payment_id' => $pay->id,
                                'amount' => $amount_comission_senior,
                                'user_id' => $senior_rep->user_id,
                                'payment_uid' => '',
                                'description' => $description_comission,
                                'payload' => '',
                                'deleted' => 1,
                                'created' => date('Y-m-d H:i:s'),
                                'createdby' => $user_id,
                            );
            
                            $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                            $this->DataSalesRepresentativePayments->save($c_entity_comission);
                            $service = 'Training';
                            $Payments->send_email_team_member_courses($user_id, $service, $type_string, $amount_comission_senior, $senior_rep);
                        }
                    }
                } else if($representative->team == 'OUTSIDE'){// Si el representante es OUTSIDE entonces $25 de comision y nada al senior
                    $amount_comission = $amount_comission == 0 ? 0 : 10000;
                }

                if(!env('IS_DEV', false)){
                    $Ghl = new GhlController();
                    $array_ghl = array(
                        'email' => $user_email,
                        'name' => $user_name,
                        'lname' => $user_lname,
                        'phone' => $user_phone,
                        'costo' => 0,
                        'column' => 'Purchased advanced (no subscription)',
                    );
                    $contactId = $Ghl->updateOpportunityTags($array_ghl);
                    $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Purchased advanced');
                    $this->set('tag', $tag);
                }
            }else if($course == 'ADVANCED TECHNIQUES MEDICAL'){
                $msg = 'MySpaLive - ' . $user_name . ' ' . $user_lname . ', has completed the advanced training purchase for $' . $total_amount / 100;
                $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . $user_name . ' ' . $user_lname . ', ' . $this->formatPhoneNumber($user_phone) . ', has completed the advanced training purchase for $' . $total_amount / 100, $Main);
                $Main->send_email_after_register($assignedRep['User']['email'],'Training purchase',$msg);

                $value_discount = $Payments->getParams('discount_amount', 0);
                /* if($value_discount <= 20000){ // 795 - 200 = 595
                    $amount_comission = $this->full_comission;
                } else */ 
                if ($value_discount <= 30000){ // 795 - 300 = 495 // 795 - 201 = 594
                    // $amount_comission = $this->half_comission;
                    $amount_comission = $this->full_comission;
                } else if($value_discount >= 30100){ // 795 - 300 = 494
                    $amount_comission = 0;
                }

                $description_comission = 'SALES TEAM LEVEL 3';
                if($representative->team == 'INSIDE'){ // Si el representante es INSIDE entonces pagamos con normalidad la comision y le generamos senior otra comision de $50
                    if($representative->rank == 'JUNIOR' ){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission = $amount_comission == 0 ? 0 : 5000;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.rank' => 'SENIOR'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay)){
                            $array_save_comission = array(
                                'uid' => Text::uuid(),
                                'payment_id' => $pay->id,
                                'amount' => $amount_comission,
                                'user_id' => $senior_rep->user_id,
                                'payment_uid' => '',
                                'description' => $description_comission,
                                'payload' => '',
                                'deleted' => 1,
                                'created' => date('Y-m-d H:i:s'),
                                'createdby' => $user_id,
                            );
            
                            $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                            $this->DataSalesRepresentativePayments->save($c_entity_comission);
                            $service = 'Training';
                            $Payments->send_email_team_member_courses($user_id, $service, $type_string, $amount_comission, $senior_rep);
                        }
                    } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay)){
                            $array_save_comission = array(
                                'uid' => Text::uuid(),
                                'payment_id' => $pay->id,
                                'amount' => $amount_comission_senior,
                                'user_id' => $senior_rep->user_id,
                                'payment_uid' => '',
                                'description' => $description_comission,
                                'payload' => '',
                                'deleted' => 1,
                                'created' => date('Y-m-d H:i:s'),
                                'createdby' => $user_id
                            );
            
                            $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                            $this->DataSalesRepresentativePayments->save($c_entity_comission);
                            $service = 'Training';
                            $Payments->send_email_team_member_courses($user_id, $service, $type_string, $amount_comission_senior, $senior_rep);
                        }
                    }
                } else if($representative->team == 'OUTSIDE'){// Si el representante es OUTSIDE entonces $25 de comision y nada al senior
                    $amount_comission = $amount_comission == 0 ? 0 : 10000;
                }
            }else{
                // Enviar SMS para otros cursos (OTCOURSE)
                $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . $user_name . ' ' . $user_lname . ', ' . $this->formatPhoneNumber($user_phone) . ', has completed the ' . $type_string . ' training purchase for $' . $total_amount / 100, $Main);
                $msg = 'MySpaLive - ' . $user_name . ' ' . $user_lname . ', has completed the ' . $type_string . ' training purchase for $' . $total_amount / 100;
                $Main->send_email_after_register($assignedRep['User']['email'],'Training purchase',$msg);

                $value_discount = $Payments->getParams('discount_amount', 0);

                if ($value_discount <= 30000){
                    $amount_comission = $this->full_comission;
                } else if($value_discount >= 30100){
                    $amount_comission = 0;
                }

                if(!env('IS_DEV', false)){
                    $Ghl = new GhlController();
                    $array_ghl = array(
                        'email' => $user_email,
                        'name' => $user_name,
                        'lname' => $user_lname,
                        'phone' => $user_phone,
                        'costo' => 0,
                        'column' => 'Registered',
                    );
                    $contactId = $Ghl->updateOpportunityTags($array_ghl);
                    $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], $type_string);
                }
                
                $description_comission = 'SALES TEAM OTHER COURSE';
            }
            
            if(!empty($pay)){
                $array_save_comission = array(
                    'uid' => Text::uuid(),
                    'payment_id' => $pay->id,
                    'amount' => $amount_comission,
                    'user_id' => $description_comission == 'SALES TEAM OTHER COURSE' ? 6101 : $assignedRep['User']['id'],
                    'payment_uid' => '',
                    'description' => $description_comission,
                    'payload' => '',
                    'deleted' => 1,
                    'created' => date('Y-m-d H:i:s'),
                    'createdby' => $user_id
                );
                $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                $saved = $this->DataSalesRepresentativePayments->save($c_entity_comission);
                if($saved){
                    $this->io->info("✓ Comisión guardada: {$description_comission} - Monto: $" . ($amount_comission / 100) . " - User ID: {$assignedRep['User']['id']} - Rank: {$representative->rank} - Team: {$representative->team}");
                    $service = 'Training';
                    $Payments->send_email_team_member_courses($user_id, $service, $type_string, $amount_comission, $assignedRep);
                    //Assign inside sales rep
                    if($course == 'BASIC COURSE') $Payments->assignRepInside();
                } else {
                    $errors = $c_entity_comission->getErrors();
                    $this->io->error("✗ Error al guardar comisión: " . json_encode($errors));
                }
            } else {
                $this->io->error("✗ No se encontró el pago (pay) para generar la comisión. Payment ID: " . ($payment ? $payment->id : 'NULL'));
            }

        #endregion

        
    }
    
    /**
     * Maneja el fallo de un pago - NO reintenta, solo marca como FAILED
     */
    private function handlePaymentFailure($payment, $result) {
        // Marcar como fallido sin reintentos
        $this->DataDeferredPayments->updateAll([
            'status' => 'FAILED',
            'executed_date' => date('Y-m-d H:i:s'),
            'error_message' => $result['error'],
            'modified' => date('Y-m-d H:i:s')
        ], [
            'id' => $payment->id
        ]);
        
        $this->io->error("Pago marcado como FALLIDO. NO se reintentará automáticamente.");
        
        // Send SMS to Jenna (default sales rep) when payment fails
        $default_rep_id = 6101;
        $Main = new MainController();
        
        // Get course name from payment type or description
        $course_name = !empty($payment->type) ? $payment->type : (!empty($payment->description) ? $payment->description : 'Unknown Course');
        
        // Get injector's name and phone
        $injector_name = '';
        $injector_phone = '';
        
        if (!empty($payment['User'])) {
            $injector_name = trim(($payment['User']['name'] ?? '') . ' ' . ($payment['User']['lname'] ?? ''));
            $injector_phone = $payment['User']['phone'] ?? '';
            
            // Format phone number if available
            if (!empty($injector_phone)) {
                $injector_phone = $this->formatPhoneNumber($injector_phone);
            }
        }
        
        // Build SMS message
        $sms_message = "The deferred payment for {$course_name}, set by {$injector_name}";
        if (!empty($injector_phone)) {
            $sms_message .= " - {$injector_phone}";
        }
        $sms_message .= " could not be processed";
        
        // Send SMS to Jenna
        try {
            $this->notificateSMS($default_rep_id, $sms_message, $Main);
            $this->io->info("✓ SMS enviado a Jenna (ID: {$default_rep_id}) sobre el pago fallido");
        } catch (\Exception $e) {
            $this->io->error("✗ Error al enviar SMS a Jenna: " . $e->getMessage());
        }
    }
    
    /**
     * Send SMS to Jenna when a deferred payment is completed successfully
     */
    private function sendSuccessSMSToJenna($payment) {
        $default_rep_id = 6101;
        $Main = new MainController();
        
        // Get course name from payment type or description
        $course_name = !empty($payment->type) ? $payment->type : (!empty($payment->description) ? $payment->description : 'Unknown Course');
        
        // Get injector's name and phone
        $injector_name = '';
        $injector_phone = '';
        
        if (!empty($payment['User'])) {
            $injector_name = trim(($payment['User']['name'] ?? '') . ' ' . ($payment['User']['lname'] ?? ''));
            $injector_phone = $payment['User']['phone'] ?? '';
            
            // Format phone number if available
            if (!empty($injector_phone)) {
                $injector_phone = $this->formatPhoneNumber($injector_phone);
            }
        }
        
        // Build SMS message
        $sms_message = "The deferred payment for {$course_name}, set by {$injector_name}";
        if (!empty($injector_phone)) {
            $sms_message .= " - {$injector_phone}";
        }
        $sms_message .= " was processed successfully";
        
        // Send SMS to Jenna
        try {
            $this->notificateSMS($default_rep_id, $sms_message, $Main);
            $this->io->info("✓ SMS enviado a Jenna (ID: {$default_rep_id}) sobre el pago exitoso");
        } catch (\Exception $e) {
            $this->io->error("✗ Error al enviar SMS a Jenna: " . $e->getMessage());
        }
    }
    
    /**
     * Registra un intento de pago en el log
     */
    private function logPaymentAttempt($payment_id, $attempt_number, $result) {
        $entity = $this->DataDeferredPaymentsLog->newEntity([
            'deferred_payment_id' => $payment_id,
            'attempt_number' => $attempt_number,
            'attempt_date' => date('Y-m-d H:i:s'),
            'status' => $result['success'] ? 'SUCCESS' : 'FAILED',
            'error_message' => $result['error'],
            'stripe_response' => $result['stripe_response'],
            'created' => date('Y-m-d H:i:s')
        ]);
        
        if (!$entity->hasErrors()) {
            $this->DataDeferredPaymentsLog->save($entity);
        }
    }
    
    /**
     * Envía correo de confirmación de pago exitoso
     */
    private function sendSuccessEmail($payment, $result) {
        $amount_formatted = number_format($payment->amount / 100, 2);
        $payment_date = date('F d, Y');
        $course_type = $payment->description ? $payment->description : $payment->type;
        $user_name = $payment['User']['name'] . ' ' . $payment['User']['lname'];
        $account_url = 'https://app.myspalive.com/';
        
        $html = "
        <!doctype html>
        <html>
        <head>
            <meta name=\"viewport\" content=\"width=device-width\">
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">
            <title>Payment Confirmation - MySpaLive</title>
            <style>
                body { background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.6; margin: 0; padding: 0; }
                .container { display: block; margin: 0 auto; max-width: 580px; padding: 10px; width: 580px; }
                .content { box-sizing: border-box; display: block; margin: 0 auto; max-width: 580px; padding: 10px; }
                .main { background: #ffffff; border-radius: 3px; width: 100%; }
                .wrapper { box-sizing: border-box; padding: 20px; }
                p { font-size: 14px; color: #333333; line-height: 1.6; margin: 10px 0; }
                a { color: #655489; text-decoration: underline; }
            </style>
        </head>
        <body>
            <div class=\"container\">
                <div class=\"content\">
                    <table class=\"main\" role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">
                        <tr>
                            <td class=\"wrapper\">
                                <p>Hi {$user_name},</p>
                                
                                <p>Your scheduled payment for <strong>{$course_type}</strong> has been successfully processed on <strong>{$payment_date}</strong>.</p>
                                
                                <p>You can now reserve your spot for the course. Access your account <a href=\"{$account_url}\">from here</a> and secure your preferred date.</p>
                                
                                <p><strong>Course:</strong> {$course_type}</p>
                                
                                <p><strong>Amount Charged:</strong> \${$amount_formatted}</p>
                                
                                <p style=\"margin-top: 30px;\">Best regards,<br>MySpaLive</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $this->sendEmail(
            $payment['User']['email'],
            "You're all set! Payment for your course is confirmed",
            $html,
            $payment->id
        );
    }
    
    /**
     * Envía correo de error de pago (versión simple)
     */
    private function sendErrorEmail($payment, $result) {
         // Handle scheduled_date - it can be a DateTime object or a string
        if (is_object($payment->scheduled_date)) {
            // If it's a DateTime/Time object, format it directly
            $payment_date = $payment->scheduled_date->format('F d, Y');
        } else {
            // If it's a string, use strtotime to convert it
            $payment_date = date('F d, Y', strtotime($payment->scheduled_date));
        }
        $course_type = $payment->description ? $payment->description : $payment->type;
        $user_name = $payment['User']['name'] . ' ' . $payment['User']['lname'];
        $account_url = 'https://app.myspalive.com/';
        
        $html = "
        <!doctype html>
        <html>
        <head>
            <meta name=\"viewport\" content=\"width=device-width\">
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">
            <title>Payment Failed - MySpaLive</title>
            <style>
                body { background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.6; margin: 0; padding: 0; }
                .container { display: block; margin: 0 auto; max-width: 580px; padding: 10px; width: 580px; }
                .content { box-sizing: border-box; display: block; margin: 0 auto; max-width: 580px; padding: 10px; }
                .main { background: #ffffff; border-radius: 3px; width: 100%; }
                .wrapper { box-sizing: border-box; padding: 20px; }
                p { font-size: 14px; color: #333333; line-height: 1.6; margin: 10px 0; }
                a { color: #655489; text-decoration: underline; }
            </style>
        </head>
        <body>
            <div class=\"container\">
                <div class=\"content\">
                    <table class=\"main\" role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">
                        <tr>
                            <td class=\"wrapper\">
                                <p>Hi {$user_name},</p>
                                
                                <p>We attempted to process your payment for <strong>{$course_type}</strong> on <strong>{$payment_date}</strong>, but the transaction was not successful.</p>
                                
                                <p>To keep your enrollment on track, please update your payment method or retry your payment as soon as possible: <a href=\"{$account_url}\">Go to the app</a></p>
                                
                                <p>If you believe this is an error or need assistance, our support team is here to help.</p>
                                
                                <p style=\"margin-top: 30px;\">Thank you,<br>MySpaLive</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $this->sendEmail(
            $payment['User']['email'],
            "We couldn't process your payment for {$course_type}",
            $html,
            $payment->id
        );
    }
    
    /**
     * Envía un correo usando Mailgun
     */
    private function sendEmail($to_email, $subject, $html_body, $payment_id) {
        try {
            $data = [
                'from' => 'MySpaLive <noreply@mg.myspalive.com>',
                'to' => $to_email,
                'subject' => $subject,
                'html' => $html_body,
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
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            
            $result = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($http_code == 200) {
                // Marcar email como enviado
                $this->DataDeferredPayments->updateAll(
                    ['email_sent' => 1],
                    ['id' => $payment_id]
                );
                $this->io->info("✓ Email enviado a: {$to_email}");
            } else {
                $this->io->warning("⚠ Error al enviar email (HTTP {$http_code})");
            }
            
        } catch (\Exception $e) {
            $this->io->error("✗ Error al enviar email: " . $e->getMessage());
        }
    }

    private function notificateSMS($user_id,$body,$Main) {
        $users_array = array( $user_id );
        $Main->notify_devices($body,$users_array,false,false, true, array(), '', array(), true, true);
    }

    private function formatPhoneNumber($str_phone) {
        //(469) 891 9026
        if (strlen($str_phone) != 10) return $str_phone;
        $restul = '(' . $str_phone[0] . $str_phone[1] . $str_phone[2] . ')' . ' ' . $str_phone[3] .  $str_phone[4] . $str_phone[5] . ' ' . $str_phone[6] .  $str_phone[7] .  $str_phone[8] .  $str_phone[9];
        return $str_phone;
    }
}
