<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text;

use PHPUnit\Framework\Constraint\Count;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;
use \PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\OtherservicesController;
use SpaLiveV1\Controller\PaymentsController;
class MerzCommand extends Command{
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
        
        $this->loadModel('SpaLiveV1.SysUsers');

        $str_query_find = "SELECT 
                            SU.name, 
                            SU.lname, 
                            SU.email, 
                            SU.state, 
                            SU.zip, 
                            SU.city, 
                            SU.street, 
                            SU.suite, 
                            CP.name AS product_name, 
                                (
                                    SELECT SUM(DPD.qty) 
                                    FROM data_purchases DP 
                                    JOIN data_purchases_detail DPD ON DPD.purchase_id = DP.id
                                    WHERE DP.user_id = SU.id 
                                    AND DPD.product_id = 48
                                    AND DP.deleted = 0 
                                    AND DP.payment <> '' 
                                    AND DP.created BETWEEN '2025-06-01 00:00:00' AND '2025-06-30 23:59:59'
                                ) AS number
                            FROM sys_users SU
                            JOIN cat_products CP ON CP.id = 48
                            WHERE SU.active = 1 
                            AND SU.deleted = 0
                            AND CP.deleted = 0
                            HAVING number > 0";
        $arr = $this->SysUsers->getConnection()->execute($str_query_find)->fetchAll('assoc');

        $spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->getCell('A1')->setValue('Provider name');
		$sheet->getStyle('A1')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('right');
        $sheet->getCell('B1')->setValue('Provider credentials');
		$sheet->getStyle('B1')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        $sheet->getStyle('B1')->getAlignment()->setHorizontal('right');
        $sheet->getCell('C1')->setValue('Provider address');
		$sheet->getStyle('C1')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        $sheet->getStyle('C1')->getAlignment()->setHorizontal('right');
        $sheet->getCell('D1')->setValue('Product name');
		$sheet->getStyle('D1')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        $sheet->getStyle('D1')->getAlignment()->setHorizontal('right');
        $sheet->getCell('E1')->setValue('Number of product vials shipped to Provider');
		$sheet->getStyle('E1')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        $sheet->getStyle('E1')->getAlignment()->setHorizontal('right');

		$initIndex = 3;//$this->log(__LINE__ . ' ' . json_encode($arr_description));
		foreach ($arr as $item) {  
            $sheet->getCell('A' . $initIndex)->setValue($item['name'].' '.$item['lname']);
            $sheet->getCell('B' . $initIndex)->setValue($item['email']);
            $sheet->getCell('C' . $initIndex)->setValue($item['suite'] . ' ' . $item['street'] . ', ' . $item['city'] . ', ' . $item['state'] . ' ' . $item['zip']);
            $sheet->getCell('D' . $initIndex)->setValue($item['product_name']);
            $sheet->getCell('E' . $initIndex)->setValue($item['number']);

			$initIndex = $initIndex + 1;
		}

		$writer = new Xlsx($spreadsheet);
        $time = date('ymdhms');
		$writer->save(TMP . 'reports' . DS . "merz.xls");

		//$this->Files->output_file(TMP . 'reports' . DS . "stripe_".$time.".xls");
        $fname = TMP . 'reports' . DS . "merz.xls";

        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            // 'to'      => 'oscar.caldera@advantedigital.com',
            'to'      => 'ashlan@myspalive.com',
            'bcc'     => 'francisco@advantedigital.com',
            // 'to'      => 'khanzab@gmail.com',
            'subject' => 'Merz Providers Database',
            'html'    => "The list of providers who purchased Xeomin is attached.",
            'attachment[1]' => curl_file_create($fname, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'merz_providers_database.xls'),
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
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($curl);

        curl_close($curl);

    }
}