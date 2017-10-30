<?php
  /**
   * User: Oliver Steiner
   * Date: 29.10.17
   * Time: 21:46
   */

  namespace Drupal\contentimport\Controller;


  use Drupal\Core\Url;
  use Symfony\Component\HttpFoundation\StreamedResponse;

  /**
   * Class ExportController
   *
   * @package Drupal\contentimport\Controller
   *
   *
   */
  class ExportController {

    private $columns;

    private $data;

    private $contentType;

    private $test;

    /**
     * ExportController constructor.
     *
     */
    public function __construct() {

      // additional fields
      $this->columns = ['title', 'langcode'];   // add body too?
      $this->data = [];
    }

    /**
     * @param      $contentType
     * @param bool $test
     *
     * @return array
     *
     */
    public function generateCsvFile($contentType, $test = FALSE) {


      // Params
      $this->contentType = $contentType;
      $this->test = $test;


      if ($this->test) {

      }


      if ($this->contentType != NULL) {

        // File
        $response = new StreamedResponse();
        $response->setCallback(function () {


          // Get Data


          if ($this->test == FALSE) {

            // Get Fieldnames
            $columns = $this->columns;
            foreach (\Drupal::entityManager()
                       ->getFieldDefinitions('node', $this->contentType) as $field_definition) {
              if (!empty($field_definition->getTargetBundle())) {
                $columns[] = $field_definition->getName();
              }
            }

            // TODO: Generate matching Examples for the fields, or / and get existing Nodes.
            $data = [];
          }
          else {

            // test
            $columns = ['Name', 'Surname', 'Age'];

            $data = [
              0 => ['Name_0', 'Surname_0', 'Age_0'],
              1 => ['Name_1', 'Surname_1', 'Age_1'],
              2 => ['Name_2', 'Surname_2', 'Age_2'],
              3 => ['Name_3', 'Surname_3', 'Age_3'],
            ];
          }

          $handle = fopen('php://output', 'w+');
          // Add the header of the CSV file
          fputcsv($handle, $columns, ',');
          // Query data from database

          foreach ($data as $row) {
            fputcsv(
              $handle, // The file pointer
              $row, // The fields
              ',' // The delimiter
            );
          }

          fclose($handle);
        });

        $filename = 'drupal_contentimporter_'.$this->contentType;

        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'.csv"');

        return $response;
      }
      else {

        // Error


        drupal_set_message('No contentType defined', 'error');
        $url = Url::fromRoute('contentimport.downloadCsvTemplate.test');

        $output = [
          'first_para' => [
            '#type' => 'markup',
            '#markup' => '<p>No contentType defined</p>',
          ],
          'second_para' => [
            '#url' => $url,
            '#title' => 'Test CSV Generator',
            '#type' => 'link',
          ],

        ];
        return $output;
      }
    }
  }