<?php

namespace Drupal\search404\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\search\Entity\SearchPage;
use Drupal\Component\Utility\Html;



/**
 * Route controller for search.
 */
class Search404Controller extends ControllerBase {

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Inject the logger channel factory interface.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('search404');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Set title for the page not found(404) page.
   */
  public function getTitle() {
    $search404_page_title = \Drupal::config('search404.settings')->get('search404_page_title');
    $title = !empty($search404_page_title) ? $search404_page_title : 'Page not found ';
    return $title;
  }

  /**
   * {@inheritdoc}
   */
  public function search404_page(Request $request) {
    $keys = $this->search404_get_keys();
    if (\Drupal::moduleHandler()->moduleExists('search') && (\Drupal::currentUser()->hasPermission('search content') || \Drupal::currentUser()->hasPermission('search by page'))) {

      // Get and use the default search engine for the site.
      $search_page_repository = \Drupal::service('search.search_page_repository');
      $default_search_page = $search_page_repository->getDefaultSearchPage();

      $entity = SearchPage::load($default_search_page);
      $plugin = $entity->getPlugin();
      $build = array();
      $results = array();

      // Build the form first, because it may redirect during the submit,
      // and we don't want to build the results based on last time's request.
      $plugin->setSearch($keys, $request->query->all(), $request->attributes->all());


      if ($keys && !\Drupal::config('search404.settings')->get('search404_skip_auto_search')) {
        //if custom search enabled.
        if (\Drupal::moduleHandler()->moduleExists('search_by_page') && \Drupal::config('search404.settings')->get('search404_do_search_by_page')) {
          drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => Html::escape($keys))), 'error', FALSE);
          $this->search404_goto('search_pages/' . $keys);
        }
        else {
          // Build search results, if keywords or other search parameters are in the
          // GET parameters. Note that we need to try the search if 'keys' is in
          // there at all, vs. being empty, due to advanced search.
          if ($plugin->isSearchExecutable()) {
            // Log the search.
            if ($this->config('search.settings')->get('logging')) {
              $this->logger->notice('Searched %type for %keys.', array('%keys' => $keys, '%type' => $entity->label()));
            }
            // Collect the search results.
            $results = $plugin->buildResults();
          }

          if (isset($results)) {
            // Jump to first result if there are results and
            // if there is only one result and if jump to first is selected or
            // if there are more than one results and force jump to first is selected.
            if (is_array($results) &&
                (
                (count($results) == 1 && \Drupal::config('search404.settings')->get('search404_jump')) || (count($results) >= 1 && \Drupal::config('search404.settings')->get('search404_first'))
                )
            ) {
              if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
                drupal_set_message(t('The page you requested does not exist. A search for %keys resulted in this page.', array('%keys' => Html::escape($keys))), 'status', FALSE);
              }
              if (isset($results[0]['#result']['link'])) {
                $result_path = $results[0]['#result']['link'];
              }
              $this->search404_goto($result_path);
            }
            else {
              if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
                drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => Html::escape($keys))), 'error', FALSE);
              }
            }
          }
        }
      }

      // Construct the search form.
      $build['search_form'] = $this->entityFormBuilder()->getForm($entity, 'search');

      //Set the custom page text on the top of the results.
      $search404_page_text = \Drupal::config('search404.settings')->get('search404_page_text');
      if (!empty($search404_page_text)) {
        $build['content']['#markup'] = '<div id="search404-page-text">' . $search404_page_text . '</div>';
        $build['content']['#weight'] = -100;
      }

      //Text for,if search results is empty.
      $no_results = t('<ul>
     <li>Check if your spelling is correct.</li>
     <li>Remove quotes around phrases to search for each word individually. <em>bike shed</em> will often show more results than <em>&quot;bike shed&quot;</em>.</li>
     <li>Consider loosening your query with <em>OR</em>. <em>bike OR shed</em> will often show more results than <em>bike shed</em>.</li>
     </ul>');
      $build['search_results'] = array(
        '#theme' => array('item_list__search_results__' . $plugin->getPluginId(), 'item_list__search_results'),
        '#items' => $results,
        '#empty' => array(
          '#markup' => '<h3>' . $this->t('Your search yielded no results.') . '</h3>' . $no_results,
        ),
        '#list_type' => 'ol',
        '#attributes' => array(
          'class' => array(
            'search-results',
            $plugin->getPluginId() . '-results',
          ),
        ),
        '#cache' => array(
          'tags' => $entity->getCacheTags(),
        ),
      );

      $build['pager'] = array(
        '#theme' => 'pager',
      );
      $build['#attached']['library'][] = 'search/drupal.search.results';
    }
    if (\Drupal::config('search404.settings')->get('search404_do_custom_search')) {
      $custom_search_path = \Drupal::config('search404.settings')->get('search404_custom_search_path');
      // Remove query parameters before checking whether the search path exists or the user
      // has access rights.
      $custom_search_path_no_query = preg_replace('/\?.*/', '', $custom_search_path);
      if (\Drupal::service('path.validator')->isValid($custom_search_path_no_query)) {
        if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
          drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => Html::escape($keys))), 'error', FALSE);
        }
        $custom_search_path = str_replace('@keys', $keys, $custom_search_path);
        $this->search404_goto($custom_search_path);
      }
    }

    if (empty($build)) {
      $build = array('#markup' => 'The page you requested does not exist.');
    }
    return $build;
  }

  /**
   * Search404 drupal_goto helper function.
   * @param string $path Path to redirect
   */
  public function search404_goto($path = '') {
    // set redirect response.
    $response = new RedirectResponse($path);
    if (\Drupal::config('search404.settings')->get('search404_redirect_301')) {
      $response->setStatusCode(301);
    }
    $response->send();
    return;
  }

  /**
   * Detect search from search engine.
   */
  public function search404_search_engine_query() {
    $engines = array(
      'altavista' => 'q',
      'aol' => 'query',
      'google' => 'q',
      'bing' => 'q',
      'lycos' => 'query',
      'yahoo' => 'p',
    );
    $parsed_url = parse_url($_SERVER['HTTP_REFERER']);
    $remote_host = !empty($parsed_url['host']) ? $parsed_url['host'] : '';
    $query_string = !empty($parsed_url['query']) ? $parsed_url['query'] : '';
    parse_str($query_string, $query);

    if (!$parsed_url === FALSE && !empty($remote_host) && !empty($query_string) && count($query)) {
      foreach ($engines as $host => $key) {
        if (strpos($remote_host, $host) !== FALSE && array_key_exists($key, $query)) {
          return trim($query[$key]);
        }
      }
    }
    return '';
  }

  /**
   * Get the keys that are to be used for the search based either
   * on the keywords from the URL or from the keys from the search
   * that resulted in the 404
   */
  public function search404_get_keys() {
    $keys = '';
    // Try to get keywords from the search result (if it was one)
    // that resulted in the 404 if the config is set.
    if (\Drupal::config('search404.settings')->get('search404_use_search_engine')) {
      $keys = $this->search404_search_engine_query();
    }
    // If keys are not yet populated from a search engine referer
    // use keys from the path that resulted in the 404.
    if (empty($keys)) {
      $keys = \Drupal::request()->server->get('REDIRECT_URL');
    }

    // Abort query on certain extensions, e.g: gif jpg jpeg png
    $extensions = explode(' ', \Drupal::config('search404.settings')->get('search404_ignore_query'));
    $extensions = trim(implode('|', $extensions));
    if (!empty($extensions) && preg_match("/\.($extensions)$/i", $keys)) {
      return FALSE;
    }

    $regex_filter = \Drupal::config('search404.settings')->get('search404_regex');
    if (!empty($regex_filter)) {
      $keys = preg_replace("/" . $regex_filter . "/i", '', $keys);
    }
    // Ignore certain extensions from query.
    $extensions = explode(' ', \Drupal::config('search404.settings')->get('search404_ignore_extensions'));
    $extensions = trim(implode('|', $extensions));
    if (!empty($extensions)) {
      $keys = preg_replace("/\.($extensions)$/i", '', $keys);
    }

    $keys = preg_split('/[' . Unicode::PREG_CLASS_WORD_BOUNDARY . ']+/u', $keys);

    // Ignore certain words (use case insensitive search).
    $keys = array_udiff($keys, explode(' ', \Drupal::config('search404.settings')->get('search404_ignore')), 'strcasecmp');
    // Sanitize the keys
    foreach ($keys as $a => $b) {
      $keys[$a] = Html::escape($b);
    }
    $modifier = \Drupal::config('search404.settings')->get('search404_use_or') ? ' OR ' : ' ';
    $keys = trim(implode($modifier, $keys));
    return $keys;
  }
}
