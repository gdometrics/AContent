<?php
/************************************************************************/
/* AContent                                                             */
/************************************************************************/
/* Copyright (c) 2010                                                   */
/* Inclusive Design Institute                                           */
/*                                                                      */
/* This program is free software. You can redistribute it and/or        */
/* modify it under the terms of the GNU General Public License          */
/* as published by the Free Software Foundation.                        */
/************************************************************************/

/**
* Patch
* Class to create a zipped patch package
* zipped patch package contains patch.xml, the files to be added, overwritten
* @access	public
* @author	Cindy Qi Li
* @package	PatchCreator
*/

define('TR_INCLUDE_PATH', '../../include/');
require_once (TR_INCLUDE_PATH.'vitals.inc.php');

require_once(TR_INCLUDE_PATH. "../updater/include/patch_xml_template.inc.php");
require_once(TR_INCLUDE_PATH."classes/DAO/MyownPatchesDAO.class.php");
require_once(TR_INCLUDE_PATH."classes/DAO/MyownPatchesDependentDAO.class.php");
require_once(TR_INCLUDE_PATH."classes/DAO/MyownPatchesFilesDAO.class.php");

class PatchCreator {

	// all private
	var $patch_info_array = array();           // the patch info data
	var $patch_xml_file;                       // location of patch.xml
	var $current_patch_id;                     // current myown_patches.myown_patch_id
	var $version_folder;                       // version folder. underneath updater content folder, to hold all patch folders and corresponding upload files
	var $patch_folder;                         // patch folder. underneath version folder, to hold all upload files
	
	var $myownPatchesDAO;                      // DAO object for table "myown_patches"
	var $myownPatchesDependentDAO;             // DAO object for table "myown_patches_dependent"
	var $myownPatchesFilesDAO;                 // DAO object for table "myown_patches_files"
	
	/**
	* Constructor: Initialize object members
	* @author  Cindy Qi Li
	* @access  public
	* @param   $patch_info_array	All information to create a patch. Example:
	* Array
	* (
	*     [system_patch_id] => Patch001
	*     [transformable_version_to_apply] => 1.6
	*     [description] => this is a sample patch info array
	*     [sql_statement] => 
	*     [dependent_patches] => Array
	*     (
	*         [0] => P2
	*         [1] => P3
	*     )
 	*     [files] => Array
	*         (
	*             [0] => Array
	*                 (
	*                     [file_name] => script1.php
	*                     [action] => add
	*                     [directory] => admin/
	*                     [upload_tmp_name] => C:\xampp\tmp\php252.tmp
	*                 )
	* 
	*             [1] => Array
	*                 (
	*                     [file_name] => script2.php
	*                     [action] => delete
	*                     [directory] => tools/
	*                 )
	*         )
	* 
	* )
	*/
	
	function PatchCreator($patch_info_array, $patch_id)
	{
		// add slashes if magic_quotes_gpc is off
		if (!get_magic_quotes_gpc())
		{
			$patch_info_array["description"] = addslashes($patch_info_array["description"]);
			$patch_info_array["sql_statement"] = addslashes($patch_info_array["sql_statement"]);
			
			for ($i = 0; $i < count($patch_info_array["files"]); $i++)
			{
				$patch_info_array["files"][$i]["directory"] = addslashes($patch_info_array["files"][$i]["directory"]);
				$patch_info_array["files"][$i]["upload_tmp_name"] = addslashes($patch_info_array["files"][$i]["upload_tmp_name"]);
				$patch_info_array["files"][$i]["code_from"] = addslashes($patch_info_array["files"][$i]["code_from"]);
				$patch_info_array["files"][$i]["code_to"] = addslashes($patch_info_array["files"][$i]["code_to"]);
			}
		}
		
		$this->patch_info_array = $patch_info_array; 
		$this->current_patch_id = $patch_id;
		
		$this->patch_xml_file = TR_CONTENT_DIR . "updater/patch.xml";

		$this->version_folder = TR_CONTENT_DIR . "updater/" . str_replace('.', '_', $this->patch_info_array["transformable_version_to_apply"]) . "/";
		$this->patch_folder = $this->version_folder . $this->patch_info_array["system_patch_id"] . "/";
		
		$this->myownPatchesDAO = new MyownPatchesDAO();
		$this->myownPatchesDependentDAO = new MyownPatchesDependentDAO();
		$this->myownPatchesFilesDAO = new MyownPatchesFilesDAO();
	}

	/**
	* Create Patch
	* @access  public
	* @return  true if created successfully
	*          false if error happens
	* @author  Cindy Qi Li
	*/
	function create_patch()
	{
		// save patch info into database & save uploaded files into content folder
		$this->saveInfo();
		
		// create patch.xml into $this->patch_xml_file
		$fp = fopen($this->patch_xml_file,'w');
		fwrite($fp,$this->createXML());
		fclose($fp);
		
		// create zip package and force download
		$this->createZIP();

		// clean up
		unlink($this->patch_xml_file);
		
		return true;
	}

	/**
	* Save patch info into database & save uploaded files into content folder
	* @access  public
	* @return  xml string
	* @author  Cindy Qi Li
	*/
	function saveInfo() 
	{
		global $db;
		
		if ($this->current_patch_id == 0)
		{
			$this->current_patch_id = $this->myownPatchesDAO->Create(
			        $this->patch_info_array["system_patch_id"], 
			        $this->patch_info_array["transformable_version_to_apply"], 
			        $this->patch_info_array["description"], 
			        $this->patch_info_array["sql_statement"]);
		}
		else
		{
			$this->myownPatchesDAO->Update($this->current_patch_id, 
			            $this->patch_info_array["system_patch_id"],
			            $this->patch_info_array["transformable_version_to_apply"],
			            $this->patch_info_array["description"],
			            $this->patch_info_array["sql_statement"]);
		}

		if ($this->current_patch_id == 0)
		{
			//$this->current_patch_id = mysql_insert_id();
			$this->current_patch_id = ac_insert_id();
		}
		else // delete records for current_patch_id in tables myown_patches_dependent & myown_patches_files
		{
			$this->myownPatchesDependentDAO->DeleteByPatchID($this->current_patch_id);
			$this->myownPatchesFilesDAO->DeleteByPatchID($this->current_patch_id);
		}
		
		// insert records into table myown_patches_dependent
		if (is_array($this->patch_info_array["dependent_patches"]))
		{
			foreach ($this->patch_info_array["dependent_patches"] as $dependent_patch)
			{
				$this->myownPatchesDependentDAO->Create($this->current_patch_id, $dependent_patch);
			}
		}
		
		// insert records into table myown_patches_files
		if (is_array($this->patch_info_array["files"]))
		{
			foreach ($this->patch_info_array["files"] as $file_info)
			{
				if ($file_info["upload_tmp_name"] <> "")
					$upload_to = $this->saveFile($file_info);
				else
					$upload_to = "";
				
				$this->myownPatchesFilesDAO->Create($this->current_patch_id, 
			                $file_info["action"], 
			                $file_info["file_name"], 
			                $file_info["directory"], 
			                $file_info["code_from"], 
			                $file_info["code_to"],
			                $upload_to);
			}
		}
	}

	/**
	* Save upload file into content folder
	* @access  private
	* @return  xml string
	* @author  Cindy Qi Li
	*/
	function saveFile($file_info) 
	{
		// mkdir() function cannot create folder recursively, so have to acomplish the creation of patch folder by 2 steps.
		if (!file_exists($this->version_folder))	mkdir($this->version_folder);
		if (!file_exists($this->patch_folder))	mkdir($this->patch_folder);
		
		$upload_to = $this->patch_folder . $file_info['file_name'];
		
		// copy uploaded file into content folder
		copy($file_info["upload_tmp_name"], $upload_to);
		
		return realpath($upload_to);
	}

	/**
	* Create patch.xml.
	* @access  private
	* @return  xml string
	* @author  Cindy Qi Li
	*/
	function createXML() 
	{
		global $patch_xml, $dependent_patch_xml;
		global $patch_action_detail_xml, $patch_file_xml;
		
		// generate content of <dependent_patches> section
		if (is_array($this->patch_info_array["dependent_patches"]))
		{
			foreach ($this->patch_info_array["dependent_patches"] as $dependent_patch)
				$dependent_patches .= str_replace('{DEPENDENT_PATCH}', $dependent_patch, $dependent_patch_xml);
		}
		
		// generate content of <files> section
		if (is_array($this->patch_info_array["files"]))
		{
			foreach ($this->patch_info_array["files"] as $file_info)
			{
				$action_details = "";
				
				if ($file_info["action"] == "alter")
				{
					$action_details .= str_replace(array('{TYPE}', '{CODE_FROM}', '{CODE_TO}'), 
									  array('replace', 
									  			htmlspecialchars(stripslashes($file_info["code_from"]), ENT_QUOTES), 
									  			htmlspecialchars(stripslashes($file_info["code_to"]), ENT_QUOTES)),
									  $patch_action_detail_xml);
				}
				
				$xml_files .= str_replace(array('{ACTION}', '{NAME}', '{LOCATION}', '{ACTION_DETAILS}'), 
									  array($file_info["action"], $file_info["file_name"], $file_info["directory"], $action_details),
									  $patch_file_xml);
			}
		}

		// generate patch.xml
		return str_replace(array('{system_patch_id}', 
		                         '{APPLIED_VERSION}', 
		                         '{DESCRIPTION}', 
		                         '{SQL}', 
		                         '{DEPENDENT_PATCHES}',
		                         '{FILES}'), 
							         array($this->patch_info_array["system_patch_id"], 
							               $this->patch_info_array["transformable_version_to_apply"], 
							               htmlspecialchars(stripslashes($this->htmlNewLine($this->patch_info_array["description"])), ENT_QUOTES), 
							               htmlspecialchars(stripslashes($this->patch_info_array["sql_statement"]), ENT_QUOTES), 
							               $dependent_patches,
							               $xml_files),
							         $patch_xml);
	}

	/**
	* Create xml for section <files> in patch.xml.
	* @access  private
	* @return  xml string
	* @author  Cindy Qi Li
	*/
	function createXMLFiles($file_info)
	{
		
		$action_details = "";
		
		if ($file_info["action"] == "alter")
		{
			$action_details .= str_replace(array('{TYPE}', '{CODE_FROM}', '{CODE_TO}'), 
							  array('replace', 
							  			htmlspecialchars(stripslashes($file_info["code_from"]), ENT_QUOTES), 
							  			htmlspecialchars(stripslashes($file_info["code_to"]), ENT_QUOTES)),
							  $patch_action_detail_xml);
		}
		
		return str_replace(array('{ACTION}', '{NAME}', '{LOCATION}', '{ACTION_DETAILS}'), 
							  array($file_info["action"], $file_info["file_name"], $file_info["directory"], $action_details),
							  $patch_file_xml);
	}
	
	/**
	* Create zip file which contains patch.xml and the files to be added, overwritten, altered; and force to download
	* @access  private
	* @return  true   if successful
	*          false  if errors
	* @author  Cindy Qi Li
	*/
	function createZIP() 
	{
		require_once(TR_INCLUDE_PATH . '/classes/zipfile.class.php');

		$zipfile = new zipfile();
	
		$zipfile->add_file(file_get_contents($this->patch_xml_file), 'patch.xml');

		if (is_array($this->patch_info_array["files"]))
		{
			foreach ($this->patch_info_array["files"] as $file_info)
			{
				if ($file_info["upload_tmp_name"] <> '')
				{
					$file_name = preg_replace('/.php$/', '.new', $file_info['file_name']);
					$zipfile->add_file(file_get_contents($file_info['upload_tmp_name']), $file_name);
				}
			}
		}

		$zipfile->send_file($this->patch_info_array["system_patch_id"]);
	}

	/**
	* replace new line string to html tag <br />
	* @access  private
	* @return  converted string
	* @author  Cindy Qi Li
	*/
	function htmlNewLine($str)
	{
		$new_line_array = array("\n", "\r", "\n\r", "\r\n");

		$found_match = false;
		
		if (strlen(trim($str))==0) return "";
		
		foreach ($new_line_array as $new_line)
			if (preg_match('/'.preg_quote($new_line).'/', $str) > 0)
			{
				$search_new_line = $new_line;
				$found_match = true;
			}
		 
		if ($found_match)
			return preg_replace('/'. preg_quote($search_new_line) .'/', "<br />", $str);
		else
			return $str;
	}
}
?>