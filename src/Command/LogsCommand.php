<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 

class LogsCommand extends Command{

    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }
    
    public function execute(Arguments $args, ConsoleIo $io){
        $isDev = env('IS_DEV', false);
        $this->verify_errors_log();        
    }

    function verify_errors_log(){
        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->DataCertificates->getConnection()->execute("set @database_1 = 'spalivemd_dev';");
        $this->DataCertificates->getConnection()->execute("set @database_2 = 'spalivemd_live';");
        $find = $this->DataCertificates->getConnection()->execute("select * 
        from (
                select COALESCE(c1.table_name, c2.table_name) as table_name,
                       COALESCE(c1.column_name, c2.column_name) as table_column,
                       c1.column_name as database1,
                       c2.column_name as database2
                from
                    (select table_name,
                            column_name
                     from information_schema.columns c
                     where c.table_schema = @database_1) c1
                right join
                         (select table_name,
                                 column_name
                          from information_schema.columns c
                          where c.table_schema = @database_2) c2
                on c1.table_name = c2.table_name and c1.column_name = c2.column_name
        
            union
        
                select COALESCE(c1.table_name, c2.table_name) as table_name,
                       COALESCE(c1.column_name, c2.column_name) as table_column,
                       c1.column_name as schema1,
                       c2.column_name as schema2
                from
                    (select table_name,
                            column_name
                     from information_schema.columns c
                     where c.table_schema = @database_1) c1
                left join
                         (select table_name,
                                 column_name
                          from information_schema.columns c
                          where c.table_schema = @database_2) c2
                on c1.table_name = c2.table_name and c1.column_name = c2.column_name
        ) tmp
        where database1 is null
              or database2 is null
        order by table_name,
                 table_column;")->fetchAll('assoc');;
        //Consulta SQL para obtener información sobre las tablas de la primera base de datos
        $result1 = $this->DataCertificates->getConnection()->execute("SELECT table_name, column_name, column_type
                 FROM information_schema.columns
                 WHERE table_schema = @database_1 AND column_type LIKE 'enum%';")->fetchAll('assoc');
       
        // Consulta SQL para obtener información sobre los campos ENUM de la primera base de datos
        $result2 = $this->DataCertificates->getConnection()->execute("SELECT table_name, column_name, column_type
                 FROM information_schema.columns
                 WHERE table_schema = @database_2 AND column_type LIKE 'enum%';")->fetchAll('assoc');
      
        // Comparar los resultados de las consultas
        $str_enum = "";
        if (!empty($result1) && !empty($result2)) {
            $enumFields1 = array();
            $enumFields2 = array();

            // Almacenar los resultados de la primera base de datos en un array
            foreach ($result1 as $row) {
                $enumFields1[$row['table_name']][$row['column_name']] = $row;                
            }            
            // Almacenar los resultados de la segunda base de datos en otro array
            foreach ($result2 as $row) {
                $enumFields2[] = $row;
                $dev_arr = array();                
                    if (isset($row['table_name'])) {       
                        $dev_arr[] = $enumFields1[$row['table_name']][$row['column_name']];                        
                        foreach ($dev_arr as $tabla) {//array values                            
                            if($tabla['column_name'] === $row['column_name']){                            
                                if($tabla['column_type'] === $row['column_type']){                                    
                                }else{
                                    //$this->log(__LINE__ . " dev" . json_encode($enumFields1[$row['table_name']][$row['column_name']] ));//dev
                                    $str_enum .=          "dev" . json_encode($enumFields1[$row['table_name']][$row['column_name']] ) . " - " . "liv" . json_encode($row) . "\n";
                                    //$this->log(__LINE__ . " liv" . json_encode($row));//live 
                                }
                                continue;
                            }else{
                                //$this->log(__LINE__ . " dev" . json_encode($enumFields1[$row['table_name']][$row['column_name']] ));//dev
                                //$this->log(__LINE__ . " liv" . json_encode($row));//live 
                                $str_enum .=          "dev" . json_encode($enumFields1[$row['table_name']][$row['column_name']] ) . " - " . "liv" . json_encode($row) . "\n";
                            }
                        }                                                                    
                    }else{
                        $this->log(__LINE__ . " " . " no existe.");
                        $str_enum .= "no existe.\n";    
                    }                
            }
            
        } else {
            $this->log("Error en las consultas: ");
        }
      
        
        $myfile = fopen("/var/www/html/apispalive/logs/db_compare_enum.txt", "w") or die("Unable to open file!");            
        fwrite($myfile, $str_enum);
        fclose($myfile);

        // subscription start        
        /*$result3 = $this->DataCertificates->getConnection()->execute("
            select id, user_id, event, payload, subscription_type, status, created,
            DATEDIFF(now(), created) datediff,
            (select count(*) from data_subscription_cancelled dsc where dsc.subscription_id = ds.id ) cancel  ,
            (select count(*) from data_subscription_payments dsp where dsp.subscription_id = ds.id ) paid  ,
            (select concat(id, ' ',name, ' ', lname,' ', email, ' ', active) from sys_users u where u.id = user_id ) st,
            (select steps  from sys_users u where u.id = user_id ) step,
            (select deleted from sys_users u where u.id = user_id ) del
            from data_subscriptions ds 
            where agreement_id >0 and deleted = 0 and status IN ( 'ACTIVE')             
            and (select count(*) from data_subscription_payments dsp where dsp.subscription_id = ds.id )  = 0
            and (select deleted from sys_users u where u.id = user_id )  = 0
            and (select count(*) from data_subscription_cancelled dsc where dsc.subscription_id = ds.id ) = 0
            and (select steps  from sys_users u where u.id = user_id ) = 'HOME'
            and DATEDIFF(now(), created) > 30
            and (select active from sys_users u where u.id = user_id )  = 1
            order by created desc;
        ")->fetchAll('assoc');

        // Comparar los resultados de las consultas
        $str_subs = ""; $str_subs .= date('Y-m-d H:m'). "\n";
        if (!empty($result3)) {            
            // Almacenar los resultados de la primera base de datos en un array
            foreach ($result3 as $row) {
                $this->log(__LINE__ . " " . json_encode($row));//dev
                $str_subs .=          " " . json_encode($row) . "\n";
            }                    
        } else {
            $this->log("Error en las consultas: ");
        }


        $myfile = fopen("/var/www/html/apispalive/logs/db_subs.txt", "w") or die("Unable to open file!");            
        fwrite($myfile, $str_subs);
        fclose($myfile);*/
        // subscription end
      
        
        $this->DataCertificates->getConnection()->execute("set @database_1 = 'null';");
        $this->DataCertificates->getConnection()->execute("set @database_2 = 'null';");
        $send_db_log = false;
        
        $db_compare_count =0;
        $db_compare_count_new =0;
        if (file_exists("/var/www/html/apispalive/logs/db_compare_count.txt")) {
            $f = fopen("/var/www/html/apispalive/logs/db_compare_count.txt", 'r');
            $db_compare_count = fgets($f);
            fclose($f);
        } else {
            //echo "The file $filename does not exist";
        }

        

        if(!empty($find)){
            $db_compare_count_new = count($find);
            $myfile = fopen("/var/www/html/apispalive/logs/db_compare_count.txt", "w") or die("Unable to open file!");            
            fwrite($myfile, $db_compare_count_new);
            fclose($myfile);           

            if($db_compare_count_new > $db_compare_count)
                $send_db_log = true;
            
            $myfile = fopen("/var/www/html/apispalive/logs/db_compare.txt", "w") or die("Unable to open file!");
            //$myfile = fopen("./db_compare.txt", "w") or die("Unable to open file!");
            fwrite($myfile, json_encode(date("Y-m-d"))."\n");
            fclose($myfile);           
            
            $myfile = fopen("/var/www/html/apispalive/logs/db_compare.txt", "a") or die("Unable to open file!");
            //$myfile = fopen("./db_compare.txt", "a") or die("Unable to open file!");
            foreach ($find as $row) {
                fwrite($myfile, json_encode($row)."\n");
            }           
            fclose($myfile);        
        }                      
        echo "verify_errors_log";
        $w = shell_exec('whoami'); 
        echo json_encode($w);        
        $result = shell_exec("sudo -S /var/www/html/verify.sh 2>&1 | tee -a /tmp/mylog 2>/dev/null >/dev/null &");
        //if(!is_null($result))
            //$this->log($result);
        /*  api --------------------------------------------------- */
            $archivo_api = fopen('/var/www/html/apispalive/config/.env','r');
        $debug_api="";
        while ($linea = fgets($archivo_api)) {
            //echo $linea.'<br/>';
            //$aux[] = $linea;    
            //$numlinea++;
            //$cadena_de_texto = 'Esta es la frase donde haremos la búsqueda';
            $cadena_buscada   = 'export DEBUG=';
            $posicion_coincidencia = strrpos($linea, $cadena_buscada);
            if ($posicion_coincidencia === false) {
                //echo "NO se ha encontrado la palabra deseada!!!!";
                $debug_api="export DEBUG no encontrado API";
            } else {
                $debug_api=$linea;break;
                 //"Éxito!!! Se ha encontrado la palabra buscada en la posición: ".$posicion_coincidencia;
            }
        }
        fclose($archivo_api);
        
        $msg_debug_api ="";
        if($debug_api !=""){
            $posicion_coincidencia = strrpos($debug_api, "false");
            if ($posicion_coincidencia === false) {
                //echo "NO se ha encontrado la palabra deseada!!!!";
                //$debug="export DEBUG no encontrado";
                $msg_debug_api="<span style='color:red'>Debug activado en API de produccion</span>";
            } else {
                $msg_debug_api="<span >Debug desactivado en API de produccion</span>";
                 //"Éxito!!! Se ha encontrado la palabra buscada en la posición: ".$posicion_coincidencia;
            }
        }
            
// ***************************** panel ************************* //
        $archivo = fopen('/var/www/html/spalivemd.panel/config/.env','r');
        $debug="";
        while ($linea = fgets($archivo)) {
            //echo $linea.'<br/>';
            //$aux[] = $linea;    
            //$numlinea++;
            //$cadena_de_texto = 'Esta es la frase donde haremos la búsqueda';
            $cadena_buscada   = 'export DEBUG';
            $posicion_coincidencia = strrpos($linea, $cadena_buscada);
            if ($posicion_coincidencia === false) {
                //echo "NO se ha encontrado la palabra deseada!!!!";
                $debug="export DEBUG no encontrado";
            } else {
                $debug=$linea;break;
                 //"Éxito!!! Se ha encontrado la palabra buscada en la posición: ".$posicion_coincidencia;
            }
        }
        fclose($archivo);
        
        $msg_debug ="";
        if($debug !=""){
            $posicion_coincidencia = strrpos($debug, "false");
            if ($posicion_coincidencia === false) {
                //echo "NO se ha encontrado la palabra deseada!!!!";
                //$debug="export DEBUG no encontrado";
                $msg_debug="<span style='color:red'>Debug activado en panel de produccion</span>";
            } else {
                $msg_debug="<span >Debug desactivado en panel de produccion</span>";
                 //"Éxito!!! Se ha encontrado la palabra buscada en la posición: ".$posicion_coincidencia;
            }
        }
        
        $value =  file_get_contents("/var/www/html/apispalive/logs/verify_sendmail.txt");

        if($value == 1 || $send_db_log){            
            
            $html_content_creator = 'Hi,
        <br><br>
        Some error logs found <br>'.$msg_debug ."<br>". $msg_debug_api;
        
        
            $data=array(
                'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                'to'      => 'francisco@advantedigital.com',
                'cc'      => 'luis@advantedigital.com',
                'subject' => 'Some error logs found',
                'html'    => $html_content_creator,
                'attachment[0]' => curl_file_create('/var/www/html/apispalive/logs/' . "apitemp.log", 'text/txt', 'log_api_01.log'),
                'attachment[1]' => curl_file_create('/var/www/html/apispalive/logs/' . "paneltemp.log", 'text/txt', 'log_panel_01.log'),
                'attachment[2]' => curl_file_create('/var/www/html/apispalive/logs/' . "phptemp.log", 'text/txt', 'log_php_03.log'),
                'attachment[3]' => curl_file_create('/var/www/html/apispalive/logs/db_compare.txt', 'text/txt', 'log_db_04.log'),
                'attachment[4]' => curl_file_create('/var/www/html/apispalive/logs/db_compare_enum.txt', 'text/txt', 'log_db_enum_05.log'),                
                'attachment[5]' => curl_file_create('/var/www/html/apispalive/logs/db_subs.txt', 'text/txt', 'db_subs.log'),                
                
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
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

            $result = curl_exec($curl);
            if (curl_errno($curl)) {
                $error_msg = curl_error($curl);$this->log(json_encode($error_msg));
            }
            curl_close($curl);
        
        }else{
            //$this->log("Not send email");
    }
}

}