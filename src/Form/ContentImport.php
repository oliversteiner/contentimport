<?php

/**
 * @file
 * Contains \Drupal\contentimport\Form\ContentImport.
 */

namespace Drupal\contentimport\Form;

use Drupal\contentimport\Controller\ContentImportController;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\Form;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Entity\EntityInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

/**
 * Configure Content Import settings for this site.
**/

class ContentImport extends ConfigFormBase { 

  public function getFormID() {
    return 'contentimport';
  }

  /**
   * {@inheritdoc}
  */

  protected function getEditableConfigNames() {
    return [
      'contentimport.settings',
    ];
  }

  /**
   * Content Import Form.
  */

  public function buildForm(array $form, FormStateInterface $form_state) {
    $ContentTypes = ContentImportController::getAllContentTypes(); 
    $selected = 0;
    $form['contentimport_contenttype'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Content Type'),
      '#options' => $ContentTypes,
      '#default_value' => $selected,
    ];  

    $form['file_upload'] = [
      '#type' => 'managed_file',
      '#title' => t('Import CSV File'),
      '#size' => 40,
      '#description' => t('Select the CSV file to be imported. '),
      '#required' => FALSE,
      '#autoupload' => TRUE,
      '#upload_validators' => array('file_validate_extensions' => array('csv'))
    ];

    $form['submit'] = [
    '#type' => 'submit', 
    '#value' => t('Import'),
    '#button_type' => 'primary',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $contentType= $form_state->getValue('contentimport_contenttype');    
    $form_state_values = $form_state->getValues();
    $csvFile = $form_state->getValue('file_upload');
    $file = File::load( $csvFile[0] );
    $file->setPermanent();
    $file->save();
    ContentImport::createNode($contentType);
  }

  /**
   * To get all Content Type Fields.
  */

  public function getFields($contentType) {
    $entityManager = \Drupal::service('entity.manager');
    $fields = []; 
    foreach (\Drupal::entityManager()
         ->getFieldDefinitions('node', $contentType) as $field_name => $field_definition) {
      if (!empty($field_definition->getTargetBundle())) {
        $fields['name'][] = $field_definition->getName();
        $fields['type'][] = $field_definition->getType();
        $fields['setting'][] = $field_definition->getSettings();
      }
    }
    return $fields;
  }
  
  /**
   * To get Reference field ids.
  */

  public function getTermReference($voc, $terms) {
    $vocName = strtolower($voc);
    $vid  = preg_replace('@[^a-z0-9_]+@','_',$vocName);
    $vocabularies = \Drupal\taxonomy\Entity\Vocabulary::loadMultiple();
   
    /** Create Vocabulary if it is not exists **/
    if (!isset($vocabularies[$vid])) {
        ContentImport::createVoc($vid, $voc);       
    }
    $termArray = explode(',', $terms);
    $termIds =[];
    foreach($termArray AS $term){            
       $term_id = ContentImport::getTermId($term, $vid);     
      if(empty($term_id)){
        $term_id = ContentImport::createTerm($voc, $term, $vid);       
      }
      $termIds[]['target_id'] = $term_id;         
    }
    return $termIds;
  }

  /**
   * To Create Terms if it is not available.
  */

  public function createVoc($vid, $voc){
    $vocabulary = \Drupal\taxonomy\Entity\Vocabulary::create(array(
      'vid' => $vid,
      'machine_name' => $vid,
      'name' => $voc,
    ));
    $vocabulary->save();    
  }

  /**
   * To Create Terms if it is not available.
  */

  public function createTerm($voc, $term, $vid){
    Term::create(array(
        'parent' => array($voc),
        'name' => $term,
        'vid' => $vid,
    ))->save();
    $termId = ContentImport::getTermId($term, $vid);
    return $termId;
  }


  /**
   * To get Termid available.
  */

  public function getTermId($term, $vid){
    $termRes = db_query('SELECT n.tid FROM {taxonomy_term_field_data} n WHERE n.name  = :uid AND n.vid  = :vid', array(':uid' =>  $term, ':vid' => $vid));
    foreach($termRes as $val){
      $term_id = $val->tid; 
    }
    return $term_id;
  }

  /**
   * To get user information based on emailIds
   */
  public static function getUserInfo($userArray) {
      $uids = [];
      foreach($userArray AS $usermail){
        $users = \Drupal::entityTypeManager()->getStorage('user')
        ->loadByProperties(['mail' => $usermail]);
        $user = reset($users);
        if ($user) {
          $uids[] = $user->id();         
        }else{
          $user = \Drupal\user\Entity\User::create();
          $user->uid = '';
          $user->setUsername($usermail);
          $user->setEmail($usermail);
          $user->set("init", $usermail);
          $user->enforceIsNew();
          $user->activate();
          $user->save();

          $users = \Drupal::entityTypeManager()->getStorage('user')
            ->loadByProperties(['mail' => $usermail]);
          $uids[] = $user->id();
        }
      }
    return $uids;   
  }

  /**
   * To import data as Content type nodes.
  */

  public function createNode($contentType){ 
    global $base_url;  
    $loc = db_query('SELECT file_managed.uri FROM file_managed ORDER BY file_managed.fid DESC limit 1', array());
    foreach($loc as $val){
      $location = $val->uri; // To get location of the csv file imported
    }
    $mimetype = mime_content_type($location);
    $fields = ContentImport::getFields($contentType);
    $fieldNames = $fields['name'];
    $fieldTypes = $fields['type'];
    $fieldSettings = $fields['setting'];
    $files = glob('sites/default/files/'.$contentType.'/images/*.*');
    $images = [];
    foreach ($files as $file_name) {
      file_unmanaged_copy($file_name, 'sites/default/files/'.$contentType.'/images/' .basename($file_name));
      $image = File::create(array('uri' => 'public://'.$contentType.'/images/'.basename($file_name)));
      $image->save();
      $images[basename($file_name)] = $image;   
    }

    if($mimetype == "text/plain"){ //Code for import csv file
      if (($handle = fopen($location, "r")) !== FALSE) {
          $nodeData = []; $keyIndex = [];
          $index = 0;
          while (($data = fgetcsv($handle)) !== FALSE) { 
            $index++;
            if ($index < 2) {
              array_push($fieldNames,'title');
              array_push($fieldTypes,'text');
              array_push($fieldNames,'langcode');
              array_push($fieldTypes,'lang');
              foreach($fieldNames AS $fieldValues){
                $i = 0;
                foreach($data AS $dataValues){
                  if($fieldValues == $dataValues){
                    $keyIndex[$fieldValues] = $i;
                  }
                  $i++;
                }
              }  
              continue;
            }
            if(!isset($keyIndex['title']) || !isset($keyIndex['langcode'])){
              drupal_set_message(t('title or langcode is missing in CSV file. Please add these fields and import again'), 'error');
              $url = $base_url."/admin/config/content/contentimport";
              header('Location:'.$url);
              exit;
            }
            for($f = 0 ; $f < count($fieldNames) ; $f++ ){
              switch($fieldTypes[$f]) {
                case 'image':                 
                  if (!empty($images[$data[$keyIndex[$fieldNames[$f]]]])) {
                    $nodeArray[$fieldNames[$f]] = array(array('target_id' => $images[$data[$keyIndex[$fieldNames[$f]]]]->id()));
                  }
                  break;
                case 'entity_reference':
                    if($fieldSettings[$f]['target_type'] == 'taxonomy_term'){
                      $reference = explode(":", $data[$keyIndex[$fieldNames[$f]]]);                  
                      if(is_array($reference) && $reference[0] != ''){
                        $terms= ContentImport::getTermReference($reference[0], $reference[1]);                 
                        $nodeArray[$fieldNames[$f]] = $terms;
                      }
                    }else if($fieldSettings[$f]['target_type'] == 'user'){
                      $userArray = explode(', ', $data[$keyIndex[$fieldNames[$f]]]);
                      $users = ContentImport::getUserInfo($userArray);
                      $nodeArray[$fieldNames[$f]] = $users;
                    }
                  break;
                case 'text_with_summary':
                case 'text_long':
                case 'text':
                  $nodeArray[$fieldNames[$f]] = ['value' => $data[$keyIndex[$fieldNames[$f]]], 'format' => 'full_html'];
                  break;
                case 'datetime':
                  $dateTime = \DateTime::createFromFormat('Y-m-d h:i:s', $data[$keyIndex[$fieldNames[$f]]]);
                  $newDateString = $dateTime->format('Y-m-d\Th:i:s');
                  $nodeArray[$fieldNames[$f]] = ["value" => $newDateString];
                  break;
                case 'timestamp':
                  $nodeArray[$fieldNames[$f]] = ["value" => $data[$keyIndex[$fieldNames[$f]]]];
                  break;
                case 'boolean':
                  $nodeArray[$fieldNames[$f]] = ($data[$keyIndex[$fieldNames[$f]]] == 'On' || $data[$keyIndex[$fieldNames[$f]]] == 'Yes') ? 1 : 0 ;
                  break;
                case 'langcode':
                  $nodeArray[$fieldNames[$f]] = ($data[$keyIndex[$fieldNames[$f]]] != '') ? $data[$keyIndex[$fieldNames[$f]]]: 'en';
                default:
                  $nodeArray[$fieldNames[$f]] = $data[$keyIndex[$fieldNames[$f]]];
                  break;
              }             
            }
            $nodeArray['type'] = strtolower($contentType);
            $nodeArray['uid'] = 1;
            $nodeArray['promote'] = 0;
            $nodeArray['sticky'] = 0;            
            if($nodeArray['title']['value'] != ''){
              $node = Node::create($nodeArray);
              $node->save();
            }            
      }
      fclose($handle);
      $url = $base_url."/admin/content";
      header('Location:'.$url);
      exit;
    }
    }
  } 
}