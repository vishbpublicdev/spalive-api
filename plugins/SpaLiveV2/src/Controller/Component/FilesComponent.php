<?php
namespace SpaLiveV1\Controller\Component;

use Cake\Utility\Text;
use Cake\ORM\Table;
use Cake\Cache\Cache;
use Cake\ORM\TableRegistry;
use Cake\Controller\Component;

class FilesComponent extends Component {
	private $buffer = 102400;

    public function initialize(array $config):void{
        $this->FileModel = TableRegistry::get('FileModel', ['table' => '_files',]);
        $this->FileModel->addBehavior('Timestamp');
        $this->MimeTypesModel = TableRegistry::get('MimeTypesModel', ['table' => '_mimetypes',]);
        $this->FileDataModel = TableRegistry::get('FileDataModel', ['table' => '_files_data',]);
    }
 
    public function load($_file_id, $datasource = '') {
        $result = false;

        $datasource = empty($datasource)? $this->getConfig('datasource') : $datasource;
        switch($datasource){
            case 'files':
                $result = $this->_load_file($_file_id);
            break; 
            case 'mysql':
                $result = $this->_load_mysql($_file_id);
            break;
        }

        return $result;
    }

    private function _load_mysql($_file_id) {
        $result = false;

        $ent_reg = $this->FileModel->find()
            ->select([
                'FileModel.uid','FileModel.name','FileModel.size','FileModel.created','FileModel.modified',
                'MimeType.type','MimeType.mimetype',
                'FileData.data',
            ])
            ->join([
                'MimeType' => [
                    'table' => '_mimetypes',
                    'type'  => 'INNER',
                    'conditions' => 'MimeType.id = FileModel._mimetype_id'
                ],
                'FileData' => [
                    'table' => '_files_data',
                    'type'  => 'INNER',
                    'conditions' => 'FileData.id = FileModel.id'
                ]
            ])
            ->where(['FileModel.id' => $_file_id,])->first();

        if(!empty($ent_reg)){
            $result = array(
                'uid' => $ent_reg->uid,
                'name' => $ent_reg->name,
                'size' => $ent_reg->size,
                'modified' => $ent_reg->modified,
                'created' => $ent_reg->created,
                'type' => $ent_reg->MimeType['type'],
                'mimetype' => $ent_reg->MimeType['mimetype'],
                'data' => $ent_reg->FileData['data'],
            );
        }

        return $result;
    }

    private function _load_file($_file_id) {
        $result = false;

        $ent_reg = $this->FileModel->find()
            ->select([
                'FileModel.uid','FileModel.name','FileModel.size','FileModel.path','FileModel.created','FileModel.modified',
                'MimeType.type','MimeType.mimetype',
            ])
            ->join([
                'MimeType' => [
                    'table' => '_mimetypes',
                    'type'  => 'INNER',
                    'conditions' => 'MimeType.id = FileModel._mimetype_id'
                ]
            ])
            ->where(['FileModel.id' => $_file_id,])->first();

        if(!empty($ent_reg)){
            $result = [
                'uid' => $ent_reg->uid,
                'name' => $ent_reg->name,
                'size' => $ent_reg->size,
                'modified' => $ent_reg->modified,
                'created' => $ent_reg->created,
                'type' => $ent_reg->MimeType['type'],
                'mimetype' => $ent_reg->MimeType['mimetype'],
                'data' => file_get_contents($this->getConfig('directory') . $ent_reg->path . DS . $ent_reg->uid),
            ];
        }

        return $result;
    }

    public function upload($file, $_file_id = 0, $datasource = '') {
        $result = false;

        $this->FileModel->getConnection()->begin();

        $this->file_name = trim($file['name']);
        $this->file_type = $file['type'];
        $this->file_path = $file['path'];
        $this->file_size = $file['size'];
        $this->file_created= isset($file['created'])? $file['created'] : date('Y-m-d H:i:s');
        $this->file_mimetype_id = $this->get_mimetype_id($this->file_type);

        if($_file_id == 0){
            $ent_row = $this->FileModel->newEntity([
                'uid' => Text::uuid(), //uniqid('', true),
                'name' => empty($this->file_name) ? "default" : $this->file_name,
                '_mimetype_id' => $this->file_mimetype_id,
                'size' => $this->file_size,
                'path' => date('Ym', strtotime($this->file_created)),
            ]);
        }else{
            $ent_reg = $this->FileModel->find()->select(['FileModel.id','FileModel.uid','FileModel.path'])->where(['FileModel.id' => $_file_id,])->first();
            if(empty($ent_reg)){
                return false;
            }

            $_file_id = $ent_reg->id;
            $_file_uid = trim($ent_reg->uid);
            $_file_path = trim($ent_reg->path);

            $ent_row = $this->FileModel->newEntity([
                'id' => $_file_id,
                'name' => $this->file_name,
                '_mimetype_id' => $this->file_mimetype_id,
                'size' => $this->file_size,
            ]);
        }

        if($this->FileModel->save($ent_row)){
            if($_file_id == 0){
                // empty
            }else{
                $ent_row->uid = $_file_uid;
                $ent_row->path = $_file_path;
            }

            if($this->_upload($ent_row, file_get_contents($this->file_path), $datasource)){
                $result = $ent_row->id;
                $this->FileModel->getConnection()->commit();
            }
        }

        if($result == false){
            $this->FileModel->getConnection()->rollback();
        }
        
        return $result;
    }


    private function _upload($File, $data, $datasource = '') {
        $result = false;

        $datasource = empty($datasource)? $this->getConfig('datasource') : $datasource;
        switch($datasource){
            case 'files':
                $result = $this->_upload_files($File, $data);
            break;
            case 'mysql':
                $result = $this->_upload_mysql($File->id, $data);
            break;
        }

        return $result;
    }

    private function _upload_files($File, $data) {
        $str_path = $this->getConfig('directory') . $File->path;

        if(!file_exists($str_path)){
            mkdir($str_path, 0777, true);
        }

        file_put_contents($str_path . DS . $File->uid, $data);

        return true;
    }

    private function _upload_mysql($id, $data) {
        $result = false;

        $ent_row = $this->FileDataModel->newEntity([
            'id' => $id,
            'data' => $data,
        ]);

        if($this->FileDataModel->save($ent_row)){
            $result = true;
        }

        return $result;
    }

    public function update_content($_file_id, $data) {
        $result = false;

        $ent_reg = $this->FileModel->find()->select(['FileModel.id','FileModel.uid','FileModel.path'])->where(['FileModel.id' => $_file_id,])->first();

        if(!empty($ent_reg)){
            if($this->_upload($ent_reg, $data) !== false){
                $result = true;
            }
        }

        return $result;
    }

    public function get_info_by_id($_id) {
        return $this->get_info_by_where(['FileModel.id' => $_id]);
    }

    public function get_info_by_name($name) {
        return $this->get_info_by_where(['FileModel.name' => $name]);
    }

    private function get_info_by_where($conditions) {
        $result = false;

        $ent_reg = $this->FileModel->find()
            ->select([
                'FileModel.id','FileModel.uid','FileModel.name','FileModel.size','FileModel.path','FileModel.created','FileModel.modified',
                'MimeType.type','MimeType.mimetype',
            ])
            ->join([
                'MimeType' => [
                    'table' => '_mimetypes',
                    'type'  => 'INNER',
                    'conditions' => 'MimeType.id = FileModel._mimetype_id'
                ]
            ])
            ->where($conditions)->first();

        if(!empty($ent_reg)){
            $result = [
                'id' => $ent_reg->id,
                'uid' => $ent_reg->uid,
                'name' => $ent_reg->name,
                'size' => $ent_reg->size,
                'modified' => $ent_reg->modified,
                'created' => $ent_reg->created,
                'type' => $ent_reg->MimeType['type'],
                'mimetype' => $ent_reg->MimeType['mimetype'],
            ];
        }

        return $result;
    }

    public function get_mimetype_id($str_file_type){
        $ent_reg = $this->MimeTypesModel->find()->select(['MimeTypesModel.id'])->where(['MimeTypesModel.mimetype' => $str_file_type])->first();

        if(empty($ent_reg)){
            $ent_reg = $this->MimeTypesModel->newEntity([
                'mimetype' => $str_file_type,
                'type' => 'Other'
            ]);
            if($this->MimeTypesModel->save($ent_reg)){
                // empty
            }
        }

        return $ent_reg->id;
    }

    public function output($_file_id) {
        $file = $this->load($_file_id);
        
        if($file !== false){
            // $this->file_uid = $file['uid'];
            $this->file_type = $file['type'];
            $this->file_name = $file['name'];
            $this->file_mimetype = $file['mimetype'];
            $this->file_mimetype = $file['mimetype'];
            $this->data = $file['data'];
            $this->file_size = strlen($this->data);
            $this->file_modified = $file['modified'];
            $this->file_is_image = $this->file_type == 'image'? true : false;

            $this->output_stream();
        }
    }

    public function output_file($file_path){
        $this->file_name = basename($file_path);
        $this->file_mimetype = mime_content_type($file_path);
        $this->data = file_get_contents($file_path);
        $this->file_size = filesize($file_path);
        $this->file_modified = filemtime($file_path);
        $this->file_is_image = getimagesize($file_path) ? true : false;

        $this->output_stream();
    }

    private function output_stream(){
        set_time_limit(0);
        ini_set("memory_limit",-1);

        if($this->file_is_image == true){
            $this->process_image();
        }

		$this->output_header();
        $this->output_buffer();

		exit;
    }

    private function get_params_named(){
        $result = [];
        $str_url = $_SERVER['REQUEST_URI'];

        $array_url = explode('/',$str_url);
        foreach ($array_url as $value) {
            $p = explode(':',$value);
            if(count($p) == 2){
                $result[$p[0]] = $p[1];
            }
        }
        return $result;
    }

    private function process_image(){
		$this->file_url_cache = md5($_SERVER['REQUEST_URI']);
        $controller = $this->_registry->getController();

		$this->params_named = $this->get_params_named(); // $controller->request->params['named'];
		// if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == strtotime($this->file_modified)){
		// 	header('Last-Modified: '.gmdate('D, d M Y H:i:s', strtotime($this->file_modified)).' GMT', true, 304);
		// 	exit;
        // }

		// $this->cache_data = Cache::read($this->file_url_cache, '_bigpanel_img_');
		$this->cache_data = false;
		if($this->cache_data !== false){
			$this->output_stream();
		}elseif(!empty($this->params_named)){
            $this->output_transform();
		}
		// $this->error();
    }

	private function output_transform(){
		$this->cr = isset($this->params_named['cr'])? $this->params_named['cr'] : false;
		$this->w = isset($this->params_named['w'])? $this->params_named['w'] : false;
		$this->h = isset($this->params_named['h'])? $this->params_named['h'] : false;
		$this->mw = isset($this->params_named['mw'])? $this->params_named['mw'] : false;
		$this->mh = isset($this->params_named['mh'])? $this->params_named['mh'] : false;
		$this->mb = isset($this->params_named['mb'])? $this->params_named['mb'] : 0;
        $this->q = isset($this->params_named['q'])? $this->params_named['q'] : 100;

		if($this->mb == 1){
			$this->q = 45;
			// $this->file_mimetype = 'image/jpeg';
		}

		$oSourceImage = imagecreatefromstring($this->data);
		$oWidth = imagesx($oSourceImage); $oHeight = imagesy($oSourceImage);

		if($this->w !== false && $this->w > 0){
			$dWidth = $this->w;
			$ratio_orig = $oWidth / $oHeight; // Calcula la PROPORCION


			// Si tambien se da el HEIGHT permitimos la DEFORMACION
			if($this->h !== false && $this->h > 0){
				$dHeight = $this->h;

			// Si no se da el HEIGHT usamos la PROPORCION para calcularlo
			} else {
				// Si se pide restringir MAXIMO HEIGHT
				if($this->mh > 0){
					$dHeight = round($this->w / $ratio_orig); //Calcula el Probable HEIGHT segun la PROPORCION

					//Si el probable HEIGHT sobrepasa el MAXIMO HEIGHT
					if($dHeight > $this->mh){
						$dHeight = $this->mh; // El HEIGHT destino es igual al MAXIMO

						$dWidth = round($dHeight * $ratio_orig); //Se ajusta el WIDTH para cumplir con el MAXIMO HEIGHT
					}
				// Si el HEIGHT no esta limitado se calcula usando la PROPORCION
				} else {
					$dHeight = round($this->w / $ratio_orig);
				}
			}
		}elseif($this->h != null && $this->h > 0){
			$ratio_orig = $oWidth / $oHeight;
			$dWidth = round($this->h * $ratio_orig);
			$dHeight = $this->h;
		} else if($this->mw != null && $this->mw > 0){
			if($oWidth > $this->mw){
				$ratio_orig = $oWidth / $oHeight;
				$dWidth = $this->mw;

				$dHeight = round($dWidth / $ratio_orig);
			}else{
				$dWidth = $oWidth;
				$dHeight = $oHeight;
			}
		} else {
			$dWidth = $oWidth;
			$dHeight = $oHeight;
		}

		$dImage = imagecreatetruecolor($dWidth, $dHeight);

		// Turn off transparency blending (temporarily)
		imagealphablending($dImage, false);

		// Create a new transparent color for image
		// $color = imagecolorallocatealpha($dImage, 0, 0, 0, 127);
		$color = imagecolorallocatealpha($dImage, 255, 255, 255, 127);

		// Completely fill the background of the new image with allocated color.
		imagefill($dImage, 0, 0, $color);

		// Restore transparency blending
		imagesavealpha($dImage, true);

		// Si la imagen se pide CROP / CORTADA
		if($this->cr){
// echo "(($oWidth / $oHeight) > ($this->w / $this->h))";exit;
			// Checamos cual PROPORCION DE WIDTH es mayor respecto
			if(($oWidth / $oHeight) > ($this->w / $this->h)){

				$crWidth = $this->w * ( $oHeight / $this->h);

				$sX = ($oWidth - $crWidth) / 2; $sY = 0;

				$oWidth = $crWidth;
			} else {
				$crHeight = $this->h * ( $oWidth / $this->w);

				$sX = 0; $sY = ($oHeight - $crHeight) / 2;

				$oHeight = $crHeight;
			}

            $dWidth = $this->w;	$dHeight = $this->h;
			imagecopyresampled( $dImage, $oSourceImage, 0, 0, $sX, $sY, $dWidth, $dHeight, $oWidth, $oHeight); // resize the image
		} else {
			imagecopyresampled( $dImage, $oSourceImage, 0, 0, 0, 0, $dWidth, $dHeight, $oWidth, $oHeight); // resize the image
        }

        ob_start();

		if( $this->file_mimetype == 'image/jpeg' || $this->file_mimetype == 'image/jpg' ){
			imagejpeg($dImage, null, $this->q);
		} elseif( $this->file_mimetype == 'image/gif' ){
			imagegif($dImage);
		} elseif( $this->file_mimetype == 'image/png' ){
			imagepng($dImage);
        }

        $this->data = ob_get_clean();
        $this->file_size = strlen($this->data);
    }

    private function output_buffer(){
        $i = $this->start;
		while (!connection_aborted() && $i <= $this->end) {
			$bytesToRead = $this->buffer;
            if(($i+$bytesToRead) > $this->end) {
                $bytesToRead = $this->end - $i + 1;
            }
			$content = substr($this->data, $i, $bytesToRead);
			echo $content;
			flush();
			// ob_flush();
			$i += $bytesToRead;
		}
		// if($this->file_is_image == true)
		// 	Cache::write($this->file_url_cache, $this->data, '_cake_imgs_');
    }

	private function output_header(){
        ob_get_clean();
        header("Content-Type: {$this->file_mimetype}");
        header("Cache-Control: max-age=2592000, public");
        header("Expires: ".gmdate('D, d M Y H:i:s', time()+2592000) . ' GMT');
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', strtotime($this->file_modified)).' GMT');
        $this->start = 0;
        $this->end   = $this->file_size - 1;
        header("Accept-Ranges: 0-".$this->end);
        header('Content-Disposition: inline; filename="'.$this->file_name.'"');

        if (isset($_SERVER['HTTP_RANGE'])) {
            $c_start = $this->start;
            $c_end = $this->end;

            list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
            if (strpos($range, ',') !== false) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes $this->start-$this->end/$this->file_size");
                exit;
            }
            if ($range == '-') {
                $c_start = $this->file_size - substr($range, 1);
            }else{
                $range = explode('-', $range);
                $c_start = $range[0];

                $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $c_end;
            }
            $c_end = ($c_end > $this->end) ? $this->end : $c_end;
            if ($c_start > $c_end || $c_start > $this->file_size - 1 || $c_end >= $this->file_size) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes $this->start-$this->end/$this->file_size");
                exit;
            }
            $this->start = $c_start;
            $this->end = $c_end;
            $length = $this->end - $this->start + 1;
            header('HTTP/1.1 206 Partial Content');
            header("Content-Length: ".$length);
            header("Content-Range: bytes $this->start-$this->end/".$this->file_size);
        } else {
            header("Content-Length: ".$this->file_size);
        }
    }

}