<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

//
// Company: Cloudmanic Labs, http://cloudmanic.com
// By: Spicer Matthews, spicer@cloudmanic.com
// Date: 10/11/2011
// Description: A wrapper class for dealing with Mailchimp
//

class Chimp
{
	private $_email_list;
	private $_vars;
	private $_list_id;
	private $_html;
	private $_text;
	private $_errors;
	private $_subject;
	private $_from_email;
	private $_from_name;
	private $_to_name;
	
	//
	// Constructor ...
	//
	function __construct()
	{
		$this->clear();
		$this->config->load('chimp');
		$this->load->library('mailchimp');
	}
	
	//
	// Add email to a list. We just set an array that will be 
	// later sent to Mailchimp. An email address is the only 
	// required field. 
	//
	function add_email($email, $first = '', $last = '', 
											$merge_vars = array(), $type = 'html')
	{
		$name = array('FNAME' => $first, 'LNAME' => $last);
		$merge_vars = array_merge($merge_vars, $name);
		
		$this->_email_list[] = array('EMAIL' => $email, 
																	'EMAIL_TYPE' => $type,
																	'MERGE_VARS' => $merge_vars);
	}
	
	//
	// Here we set a list id for when we subscribe users to a list.
	//
	function set_list_id($id)
	{
		$this->_list_id = $id;
	}
	
	//
	// Send a transactional Email. Based off of this http://goo.gl/Gn9T1
	//
	function send_email()
	{
		// Make sure we set the transactional email list in MC
		if($this->_list_id <= 0)
		{
			if(! $this->config->item('trans_list_id'))
			{
				show_error('You must set your trans_list_id in the configuration.');
			}
			$this->_list_id = $this->config->item('trans_list_id');
		}
	
		// Make sure we added at least one email to send to.
		if(count($this->_email_list) <= 0)
		{
			show_error('Your email list is empty. Please add at least one email via add_email().');
		}
		
		// Clear out any old list members. Every transaction is a brand new list.
		$this->clear_list();
		
		// Load up the var merge index.
		$this->_set_var_index();
		
		// Loop through and subscribe the emails to the trans list.
		foreach($this->_email_list AS $key => $row)
		{
			// Double check that the list accepts all the merge vars. If not add them.
			foreach($row['MERGE_VARS'] AS $key2 => $row2)
			{
				if(! isset($this->_vars[strtoupper($key2)]))
				{
					$this->mailchimp->listMergeVarAdd($this->_list_id, strtoupper($key2), $key2);
				}
			}
		
			// Subscribe the user to the list.
			$this->mailchimp->listSubscribe($this->_list_id, $row['EMAIL'], $row['MERGE_VARS'], 
																				$row['EMAIL_TYPE'], FALSE, TRUE, TRUE, FALSE);
		}
		
		// List is in place lets create a new campaign.
		$options['list_id'] = $this->_list_id;
		$options['subject'] = $this->_subject;
		$options['from_email'] = $this->_from_email;
		$options['from_name'] = $this->_from_name;
		$options['to_name'] = $this->_to_name;
		
		$content['html'] = $this->_html;
		$content['text'] = $this->_text;
		
		$cam_id = $this->mailchimp->campaignCreate('trans', $options, $content);
		$this->_set_error();
		
		
		// The list is in place time to send the campaign
		if($cam_id)
		{
			$this->mailchimp->campaignSendNow($cam_id);
			$this->_set_error();
			echo $this->mailchimp->errorMessage;
			echo "Sent";
			$this->clear();
			return 1;
		}
		
		return 0;
	}
	
	//
	// Returns an array of all campaigns in the system.
	//
	function get_campaigns()
	{
		return $this->mailchimp->campaigns();
	}
	
	//
	// Take a list and completely delete all users in the list.
	//
	function clear_list()
	{
		$limit = 1500;
		$offset = 0;
		
		do {
			$list = $this->mailchimp->listMembers($this->_list_id, 'subscribed', NULL, $offset, $limit);
			
			$offset += $limit;
			
			foreach($list['data'] AS $key => $row)
			{
				$emails[] = $row['email'];
			}
			
			// Batch clear.
			if(isset($emails))
			{
				$this->mailchimp->listBatchUnsubscribe($this->_list_id, $emails, TRUE, FALSE, FALSE);
				unset($emails);
			}
		} while($offset < $list['total']);
	}
	
	//
	// Set Html.
	//
	function set_html($html)
	{
		$this->_html = $html;
	}
	
	//
	// Set Text.
	//
	function set_text($text)
	{
		$this->_text = $text;
	}
	
	//
	// Set Subject.
	//
	function set_subject($text)
	{
		$this->_subject = $text;
	}
	
	//
	// Set From Email.
	//
	function set_from($email, $name)
	{
		$this->_from_email = $email;
		$this->_from_name = $name;
	}
	
	//
	// Set To Email
	//
	function set_to_name($name)
	{
		$this->_to_name = $name;
	}
	
	//
	// Clear any vars after some final action.
	//
	function clear()
	{
		$this->_email_list = array();
		$this->_vars = array();
		$this->_list_id = 0;
		$this->_html = '';
		$this->_text = '';
		$this->errors = array();
		$this->_subject = '';
		$this->_from_email = '';
		$this->_from_name = '';
		$this->_to_name = '';
	}
	
	// ----------------- Private Helper Functions ---------------- //
	
	//
	// Set the error message if there is any.
	//
	private function _set_error()
	{
		if(! empty($this->mailchimp->errorMessage)) 
		{ 
			$this->_errors[] = array('code' =>  $this->mailchimp->errorCode, 
																'msg' => $this->mailchimp->errorMessage); 
		}
	}
	
	// 
	// Find out what merge vars this list accepts.
	//
	private function _set_var_index()
	{
		// Grab the merge_vars this list accepts.
		$vars = $this->mailchimp->listMergeVars($this->_list_id);
		foreach($vars AS $key => $row)
		{
			$this->_vars[$row['tag']] = $row; 
		}
	}
	
	//
	// Give us access to the CI super object.
	//
	function __get($key)
	{
		$CI =& get_instance();
		return $CI->$key;
	}
}


/* End File */