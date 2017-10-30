<?php

  namespace Drupal\contentimport\Form;

  use Drupal\contentimport\Controller\ContentImportController;
  use Drupal\Core\Ajax\AjaxResponse;
  use Drupal\Core\Ajax\InvokeCommand;
  use Drupal\Core\Ajax\ReplaceCommand;
  use Drupal\Core\Form\ConfigFormBase;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Link;
  use Drupal\node\Entity\Node;
  use Drupal\file\Entity\File;
  use Drupal\taxonomy\Entity\Vocabulary;
  use Drupal\taxonomy\Entity\Term;
  use Drupal\user\Entity\User;

  /**
   * Configure Content Import settings for this site.
   */
  class ContentImport extends ConfigFormBase {

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
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
     * {@inheritdoc}
     */
    protected function getLangcodeOptions() {


      // get installed languages:

      $languages = \Drupal::languageManager()->getLanguages();
      $optiongrouplabel = 'Installed Languages';


      $select['csv'] = 'from CSV column \'langcode\'';
      $select['-no-'] = 'don\'t use langcode';


      foreach ($languages as $key) {
        $select[$optiongrouplabel][$key->getId()] = $key->getName();
      }

      return $select;
    }

    /**
     * {@inheritdoc}
     */
    public function showCSVColumnNames(array &$form, FormStateInterface $form_state) {

      // Get Fieldnames
      $contentType = $form_state->getValue('contentimport_contenttype');


      // Get Fieldnames
      $fields = ['title', 'langcode'];   // add body too?

      foreach (\Drupal::entityManager()
                 ->getFieldDefinitions('node', $contentType) as $field_definition) {
        if (!empty($field_definition->getTargetBundle())) {
          $fields[] = $field_definition->getName();
        }
      }


      // HTML --------
      $html = '<div class="content-import-columnnames">';

      // html label and trigger
      $html .= '<div id="show-csv-columns-trigger" onclick="jQuery(\'#csv-columns\').toggle()">';
      $html .= '    <label>Show Column Names for Content Type <i>' . $contentType . '</i></label>';
      $html .= '</div>';

      // html ul
      $html .= '<div id="csv-columns" style="display: none">';
      $html .= '    <ul>';

      // HTML li
      foreach ($fields as $name) {
        $html .= '        <li>' . $name . '</li>';
      }

      // html ul
      $html .= '    </ul>';
      $html .= '</div>';

      // HTML --------
      $html .= '</div>';


      // Link fpr Download Template
      $link = link::createFromRoute('Download CSV Template for ' . $contentType, 'contentimport.downloadCsvTemplate', ['contentType' => $contentType]);

      // Output to #edit-template-output
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('#edit-ajax-container-columnnames', '<div id="edit-ajax-container-columnnames">' . $html . '</div>'));
      $response->addCommand(new ReplaceCommand('#edit-ajax-container-download-link', '<div id="edit-ajax-container-download-link">' . $link->toString() . '</div>'));

      return $response;

    }


    /**
     * Content Import Form.
     */
    public function buildForm(array $form, FormStateInterface $form_state) {

      // ContentTypes
      $contentTypes = ContentImportController::getAllContentTypes();
      array_unshift($contentTypes, 'Select Content Type');
      $contentTypesOptionsSelected = 0;

      // Langcode
      $langcodeOptionsSelected = 'csv';


      // download csv template with all  fields

      $form['contentimport_contenttype'] = [
        '#type' => 'select',
        '#title' => $this->t('Select Content Type'),
        '#options' => $contentTypes,
        '#default_value' => $contentTypesOptionsSelected,
        '#ajax' => [
          'callback' => 'Drupal\contentimport\Form\ContentImport::showCSVColumnNames',
          'event' => 'change',
          'wrapper' => 'template-output',
          'progress' => [
            'type' => 'throbber',
            'message' => t('Loading Column Names ...'),
          ],
        ],
      ];

      // language Options: Use csv , use none , list installed languages

      $form['contentimport_langcode'] = [
        '#type' => 'select',
        '#title' => $this->t('Handle Language'),
        '#options' => $this->getLangcodeOptions(),
        '#default_value' => $langcodeOptionsSelected,
      ];

      $form['file_upload'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Import CSV File'),
        '#size' => 40,
        '#description' => $this->t('Select the CSV file to be imported.'),
        '#required' => FALSE,
        '#autoupload' => TRUE,
        '#upload_validators' => ['file_validate_extensions' => ['csv']],
      ];

      $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Import'),
        '#button_type' => 'primary',
      ];


      $form['ajax_container_columnnames'] = [
        '#type' => 'container',
      ];

      $form['ajax_container_download_link'] = [
        '#type' => 'container',
      ];


      return parent::buildForm($form, $form_state);
    }

    /**
     * Content Import Form Submission.
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
      $contentType = $form_state->getValue('contentimport_contenttype');
      $langcode = $form_state->getValue('contentimport_langcode');
      $csvFile = $form_state->getValue('file_upload');
      $file = File::load($csvFile[0]);
      $file->setPermanent();
      $file->save();
      ContentImport::createNode($contentType, $langcode);
    }

    /**
     * To get all Content Type Fields.
     */
    public function getFields($contentType) {
      $fields = [];
      foreach (\Drupal::entityManager()
                 ->getFieldDefinitions('node', $contentType) as $field_definition) {
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
      $vid = preg_replace('@[^a-z0-9_]+@', '_', $vocName);
      $vocabularies = Vocabulary::loadMultiple();
      /* Create Vocabulary if it is not exists */
      if (!isset($vocabularies[$vid])) {
        ContentImport::createVoc($vid, $voc);
      }
      $termArray = explode(',', $terms);
      $termIds = [];
      foreach ($termArray as $term) {
        $term_id = ContentImport::getTermId($term, $vid);
        if (empty($term_id)) {
          $term_id = ContentImport::createTerm($voc, $term, $vid);
        }
        $termIds[]['target_id'] = $term_id;
      }
      return $termIds;
    }

    /**
     * To Create Terms if it is not available.
     */
    public function createVoc($vid, $voc) {
      $vocabulary = Vocabulary::create([
        'vid' => $vid,
        'machine_name' => $vid,
        'name' => $voc,
      ]);
      $vocabulary->save();
    }

    /**
     * To Create Terms if it is not available.
     */
    public function createTerm($voc, $term, $vid) {
      Term::create([
        'parent' => [$voc],
        'name' => $term,
        'vid' => $vid,
      ])->save();
      $termId = ContentImport::getTermId($term, $vid);
      return $termId;
    }

    /**
     * To get Termid available.
     */
    public function getTermId($term, $vid) {
      $termRes = db_query('SELECT n.tid FROM {taxonomy_term_field_data} n WHERE n.name  = :uid AND n.vid  = :vid', [
        ':uid' => $term,
        ':vid' => $vid,
      ]);
      foreach ($termRes as $val) {
        $term_id = $val->tid;
      }
      return $term_id;
    }

    /**
     * To get user information based on emailIds.
     */
    public static function getUserInfo($userArray) {
      $uids = [];
      foreach ($userArray as $usermail) {
        $users = \Drupal::entityTypeManager()->getStorage('user')
          ->loadByProperties([
            'mail' => $usermail,
          ]);
        $user = reset($users);
        if ($user) {
          $uids[] = $user->id();
        }
        else {
          $user = User::create();
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
    public function createNode($contentType, $langcode) {


      global $base_url;
      $loc = db_query('SELECT file_managed.uri FROM file_managed ORDER BY file_managed.fid DESC limit 1', []);
      foreach ($loc as $val) {
        $location = $val->uri;
      }
      $mimetype = mime_content_type($location);
      $fields = ContentImport::getFields($contentType);
      $fieldNames = $fields['name'];
      $fieldTypes = $fields['type'];
      $fieldSettings = $fields['setting'];
      $files = glob('sites/default/files/' . $contentType . '/images/*.*');
      $images = [];
      foreach ($files as $file_name) {
        file_unmanaged_copy($file_name, 'sites/default/files/' . $contentType . '/images/' . basename($file_name));
        $image = File::create(['uri' => 'public://' . $contentType . '/images/' . basename($file_name)]);
        $image->save();
        $images[basename($file_name)] = $image;
      }

      // Code for import csv file.
      if ($mimetype == "text/plain" || $mimetype == 'text/x-pascal' || $mimetype == 'text/csv') {
        if (($handle = fopen($location, "r")) !== FALSE) {
          $keyIndex = [];
          $index = 0;
          while (($data = fgetcsv($handle)) !== FALSE) {
            $index++;
            if ($index < 2) {
              array_push($fieldNames, 'title');
              array_push($fieldTypes, 'text');

              if ($langcode != '-no-') {
                array_push($fieldNames, 'langcode');
              }

              array_push($fieldTypes, 'lang');
              foreach ($fieldNames as $fieldValues) {
                $i = 0;
                foreach ($data as $dataValues) {
                  if ($fieldValues == $dataValues) {
                    $keyIndex[$fieldValues] = $i;
                  }
                  $i++;
                }
              }
              continue;
            }
            /* TITLE CHECK */
            if (!isset($keyIndex['title'])) {
              drupal_set_message($this->t('title   is missing in CSV file. Please add this field and import again'), 'error');
              $url = $base_url . "/admin/config/content/contentimport";
              header('Location:' . $url);
              exit;
            }
            /* LANGCODE CHECK */
            if (($langcode === 'csv') && !isset($keyIndex['langcode'])) {
              drupal_set_message($this->t('langcode is missing in CSV file. Please add this field and import again'), 'error');
              $url = $base_url . "/admin/config/content/contentimport";
              header('Location:' . $url);
              exit;
            }

            for ($f = 0; $f < count($fieldNames); $f++) {
              switch ($fieldTypes[$f]) {
                case 'image':
                  if (!empty($images[$data[$keyIndex[$fieldNames[$f]]]])) {
                    $nodeArray[$fieldNames[$f]] = [['target_id' => $images[$data[$keyIndex[$fieldNames[$f]]]]->id()]];
                  }
                  break;

                case 'entity_reference':
                  if ($fieldSettings[$f]['target_type'] == 'taxonomy_term') {
                    $reference = explode(":", $data[$keyIndex[$fieldNames[$f]]]);
                    if (is_array($reference) && $reference[0] != '') {
                      $terms = ContentImport::getTermReference($reference[0], $reference[1]);
                      $nodeArray[$fieldNames[$f]] = $terms;
                    }
                  }
                  elseif ($fieldSettings[$f]['target_type'] == 'user') {
                    $userArray = explode(', ', $data[$keyIndex[$fieldNames[$f]]]);
                    $users = ContentImport::getUserInfo($userArray);
                    $nodeArray[$fieldNames[$f]] = $users;
                  }
                  break;

                case 'text_with_summary':
                case 'text_long':
                case 'text':
                  $nodeArray[$fieldNames[$f]] = [
                    'value' => $data[$keyIndex[$fieldNames[$f]]],
                    'format' => 'full_html',
                  ];
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
                  $nodeArray[$fieldNames[$f]] = ($data[$keyIndex[$fieldNames[$f]]] == 'On' || $data[$keyIndex[$fieldNames[$f]]] == 'Yes') ? 1 : 0;
                  break;

                case 'langcode':


                  if ($langcode == 'csv') {

                    // get it from the csv file

                    $nodeArray[$fieldNames[$f]] = ($data[$keyIndex[$fieldNames[$f]]] != '') ? $data[$keyIndex[$fieldNames[$f]]] : 'en';

                  }
                  // dont use langcode
                  elseif ($langcode == '-no-') {

                  }
                  // get it from select input ('en','de')
                  else {
                    $nodeArray[$fieldNames[$f]] = $data[$keyIndex[$fieldNames[$f]]];
                  }
                  break;

                default:
                  $nodeArray[$fieldNames[$f]] = $data[$keyIndex[$fieldNames[$f]]];
                  break;


              }
            }
            $nodeArray['type'] = strtolower($contentType);
            $nodeArray['uid'] = 1;
            $nodeArray['promote'] = 0;
            $nodeArray['sticky'] = 0;
            if ($nodeArray['title']['value'] != '') {
              $node = Node::create($nodeArray);
              $node->save();
            }
          }
          fclose($handle);
          $url = $base_url . "/admin/content";
          header('Location:' . $url);
          exit;
        }
      }
    }

  }
