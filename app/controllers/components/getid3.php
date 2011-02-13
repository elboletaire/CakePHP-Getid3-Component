<?php
class Getid3Component extends Object
{
	public $errors = array();
	public $getID3;

	public function __construct()
	{
		set_time_limit(20*3600);
		ignore_user_abort(false);
	}
	
	public function startup(&$controller, $settings = array())
	{
		$this->controller = $controller;
		
		// Initialize getID3 engine
		App::import('Vendor', 'getID3', array('file'=>'getid3.php'));
		$this->getID3 = new getID3;
	}

	private function error($text)
	{
		if ( !is_array($text) )
			array_push($this->errors, $text);
		else {
			foreach ( $text as $t ) {
				array_push($this->errors, $t);
			}
		}
	}

	public function extract($filename)
	{
		$this->getID3->setOption(array('encoding' => Configure::read('App.encoding')));
		
		// Analyze file and store returned data in $ThisFileInfo
		$ThisFileInfo = $this->getID3->analyze($filename);
		
		return $ThisFileInfo;
	}
	
	public function read($filename) { return $this->extract($filename); }
	
	public function getId3Clean($filename)
	{
		$info = $this->read($filename);

		$id3 = array();
		foreach ( $info['tags'] as $tag )
		{
			foreach ( $tag as $key => $val )
			{
				if ( empty($id3[$key]) )
				{
					$id3[$key] = $val[0];
				}
				else
				{
					if ( strlen($val[0]) > strlen($id3[$key]) ) 
					{
						$id3[$key] = $val[0];
					}
				}
			}
		}
		return $id3;
	}
	
	public function getCustomTags($filename, $tags = array())
	{
		$id3 = $this->getId3Clean($filename);
		$vars = array(
			'description'	=> 'content_group_description',
			'set'			=> 'part_of_a_set'
		);
		$vars = array_merge($vars, $tags);

		foreach ( $vars as $k => $v )
		{
			if ( !empty($id3[$v]) )
			{
				$id3[$k] = $id3[$v];
				unset($id3[$v]);
			}
		}
		return $id3;
	}
	
	public function write($filename, $data)
	{
		$this->getID3->setOption(array('encoding'=>Configure::read('App.encoding')));

		App::import('Vendor', 'getid3_writetags', array('file' => 'write.php'));

		// Initialize getID3 tag-writing module
		$tagwriter = new getid3_writetags;
		
		//$tagwriter->filename       = '/path/to/file.mp3';
		$tagwriter->filename       = $filename;
		$tagwriter->tagformats     = array('id3v1', 'id3v2.3');
		
		// set various options (optional)
		$tagwriter->overwrite_tags = true;
		$tagwriter->tag_encoding   = Configure::read('App.encoding');
		$tagwriter->remove_other_tags = true;
		
		// populate data array
		$TagData['title'][]		= !empty($data['title'])	? $data['title'] : null;
		$TagData['artist'][]	= !empty($data['artist'])	? $data['artist'] : null;
		$TagData['album'][]		= !empty($data['album'])	? $data['album'] : null;
		$TagData['year'][]		= !empty($data['year'])		? $data['year'] : null;
		$TagData['genre'][]		= !empty($data['genre'])	? $data['genre'] : null;
		$TagData['comment'][]	= !empty($data['comment'])	? $data['comment'] : null;
		$TagData['track'][]		= !empty($data['track'])	? $data['track'] : null;
		
		if ( !empty($data['images']) ) {
			$image_types = array('cover' => 3, 'back' => 4, 'cd' => 6);
			if ( is_array($data['images']) ) {
				$i = 0;
				foreach ( $data['images'] as $image ) {
					if ( isset($image_types[$image['type']]) ) {
						$TagData['attached_picture'][$i]['data'] = file_get_contents($image['image']);
						$TagData['attached_picture'][$i]['picturetypeid'] = $image_types[$image['type']];
						$TagData['attached_picture'][$i]['mime'] = $image['mime'];
						$TagData['attached_picture'][$i]['description'] = isset($image['description']) ? $image['description'] : null;
						$i++;
					}
				}
			}
		}
		
		$tagwriter->tag_data = $TagData;

		// write tags
		if ($tagwriter->WriteTags()) {
			if (!empty($tagwriter->warnings)) {
				$this->error($tagwriter->warnings);
			}
			return true;
		} else {
			$this->error($tagwriter->errors);
			return false;
		}
	}

	public function joinMp3($file_out, $files_in)
	{
		foreach ( $files_in as $nextinputfilename ) {
			if ( !is_readable($nextinputfilename) ) {
				$this->error('Cannot read "' . $nextinputfilename . '"');
			}
		}
		if ( !empty($this->errors) ) return false;

		if ( !is_writeable(dirname($file_out)) ) {
			$this->error('Cannot write "' . $file_out . '"');
			return false;
		}

		if ( $fp_output = @fopen($file_out, 'wb') ) {
			foreach ($files_in as $nextinputfilename) {

				$current_file_info = $this->getID3->analyze($nextinputfilename);
				if ($current_file_info['fileformat'] == 'mp3') {

					if ($fp_source = @fopen($nextinputfilename, 'rb')) {

						$current_output_position = ftell($fp_output);

						// copy audio data from first file
						fseek($fp_source, $current_file_info['avdataoffset'], SEEK_SET);
						while ( !feof($fp_source) && (ftell($fp_source) < $current_file_info['avdataend']) ) {
							fwrite($fp_output, fread($fp_source, 32768));
						}
						
						fclose($fp_source);
						// trim post-audio data (if any) copied from first file that we don't need or want
						$end_offset = $current_output_position + ($current_file_info['avdataend'] - $current_file_info['avdataoffset']);
						fseek($fp_output, $end_offset, SEEK_SET);
						ftruncate($fp_output, $end_offset);
					} else {
						$this->error('failed to open "'.$nextinputfilename.'" for reading');
						fclose($fp_output);
						return false;

					}
				} else {
					$this->error('"'.$nextinputfilename.'" is not MP3 format');
					fclose($fp_output);
					return false;
				}
			}
		} else {
			$this->error('failed to open "'.$file_out.'" for writing');
			return false;
		}
		fclose($fp_output);
		return true;
	}
}
