<?php

namespace Drupal\charts_overrides\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\ResultRow;
use Drupal\Core\Database\Database;

/**
 * Provides a Views handler that exposes a WHO field.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("field_exposed_who_field")
 */
class ExposedWhoField extends FieldPluginBase {


  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['country_name'] = ['default' => 'Tajikistan'];
    $options['table_name'] = ['default' => 'TB_WithTargets'];
    $options['field_name'] = ['default' => 'c_newinc'];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $countryOptions = [];
    $countries = $this->termCountries();
    foreach ($countries as $key => $name) {
      $countryOptions[$key] = $name;
    }

    $tableOptions = [];
    $dbs = $this->charts_overrides_get_database_schemas();
    $tables = [];
    foreach ($dbs as $dname => $db) {
      // Iterate through each table.
      foreach ($db as $table) {
        $tables[$table[0]] = $table[0];
      }
    }
    foreach ($tables as $key => $name) {
      $tableOptions[$key] = $name;
    }

    $form['country_name'] = [
      '#title' => $this->t('Which country should be used?'),
      '#type' => 'select',
      '#default_value' => $this->options['country_name'],
      '#options' => $countryOptions,
    ];
    $form['table_name'] = [
      '#title' => $this->t('In which table data should be pulled from?'),
      '#type' => 'select',
      '#default_value' => $this->options['table_name'],
      '#options' => $tableOptions,
    ];
    $form['field_name'] = [
      '#title' => $this->t('Which field should be represented?'),
      '#type' => 'textfield',
      '#default_value' => $this->options['field_name'],
    ];
  }

  /**
   * @{inheritdoc}
   */
  public function render(ResultRow $values) {

    $country = $this->options['country_name'];
    $year = $this->termNames($values->node__field_year_field_year_target_id);
    $year = intval($year);
    $field = $this->options['field_name'];
    $table = $this->options['table_name'];

    Database::setActiveConnection('tbdiah');

    $db = Database::getConnection();

    $query = $db->select($table, 'td');
    $query->addField('td', $field);
    $query->condition('td.country', $country);
    $query->condition('td.year', $year);
    $ind = $query->execute();
    $ivalue = $ind->fetchField();

    Database::setActiveConnection();

    return $ivalue;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // This is not a real field and it does not affect the query. But Views
    // won't render if the query() method is not present. This doesn't do
    // anything, but it has to be here. This function is a void so it doesn't
    // return anything.
  }

  function termNames($tid) {
    $query = \Drupal::database()->select('taxonomy_term_field_data', 'td');
    $query->addField('td', 'name');
    $query->condition('td.tid', $tid);
    // For better performance, define the vocabulary where to search.
    // $query->condition('td.vid', $vid);
    $term = $query->execute();
    $tname = $term->fetchField();
    return $tname;
  }

  function termCountries() {
    $query = \Drupal::database()->select('taxonomy_term_field_data', 'td');
    $query->addField('td', 'name');
    $query->condition('td.vid', 'countries');
    $terms = $query->execute();
    $tnames = $terms->fetchAll();
    $countries = [];
    foreach ($tnames as $countryObject) {
      $countries[$countryObject->name] = $countryObject->name;
    }

    return $countries;
  }

  /**
   * Provides organizational categories for each data type.
   */
  function charts_overrides_get_data_types() {
    $types = [
      'numeric' => [
        'int',
        'decimal',
        'numeric',
        'float',
        'double',
        'bit',
      ],
      'date' => [
        'date',
        'time',
        'year',
      ],
      'string' => [
        'char',
        'binary',
        'blob',
        'text',
        'enum',
        'set',
      ],
    ];

    return $types;
  }

  function charts_overrides_get_database_schemas() {
    $dbs = [];
    $databases = Database::getAllConnectionInfo();
    // Iterate through each of the database configurations.
    foreach ($databases as $key => $datab) {
      // Taking the tbdiah database.
      if ($key == 'tbdiah') {
        if (strtolower($datab['default']['driver']) == 'mysql') {
          // Add table list to the database list.
          $dbs[$key] = $this->charts_overrides_get_database_schema_mysql($key);
        }
      }
    }
    return $dbs;
  }

  function charts_overrides_get_database_schema_mysql($key) {
    // Load the appropriate data type groups.
    $types = $this->charts_overrides_get_data_types();
    // Switch to database in question.
    Database::setActiveConnection($key);
    // The database in question.
    $new_db = Database::getConnection('default', $key);
    // Get a list of the tables in this database.
    $tables = $new_db->query('SHOW TABLES;');
    // Switch back to the main database.
    Database::setActiveConnection('default');
    $tablelist = [];
    // Fetch a row, each with a table name.
    while ($row = $tables->fetchAssoc()) {
      // This is the one of two database formats that can have whacky table
      // names due to using information_schema.  We have the ability to
      // check on columns without the PDO table substitution problem.
      foreach ($row as $v) {
        // Switch to database in question.
        Database::setActiveConnection($key);
        // Fetch column names and their data type from said table.
        $q = 'SELECT column_name, data_type FROM ';
        $q .= 'information_schema.columns WHERE table_name = :table;';
        $cols = $new_db->query($q, [':table' => $v]);
        // Switch back to the main database.
        Database::setActiveConnection('default');
        $collist = [];
        // Fetch a row, each with a column name.
        while ($r = $cols->fetchAssoc()) {
          $t = 'broken';
          // Add column to column list.
          if (isset($r['column_name'])) {
            foreach ($types as $type => $matches) {
              foreach ($matches as $match) {
                if (stristr($r['data_type'], $match)) {
                  $t = $type;
                }
              }
            }
            $collist[] = [$t, $r['column_name']];
          }
        }
        // Add table and its columns to the table list.
        $tablelist[] = [$v, $collist];
      }
    }

    return $tablelist;
  }

}
