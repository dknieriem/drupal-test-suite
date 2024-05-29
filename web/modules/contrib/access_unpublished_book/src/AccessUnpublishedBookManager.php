<?php

namespace Drupal\access_unpublished_book;

use Drupal\access_unpublished\TokenGetter;
use Drupal\book\BookManager;
use Drupal\book\BookManagerInterface;
use Drupal\book\BookOutlineStorageInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/** 
 * Defines a BookManager.
 * 
 * Overrides BookManager::doBookTreeCheckAccess.
 */
class AccessUnpublishedBookManager extends BookManager implements BookManagerInterface {

  /** 
   * Static DB connection for checking hash authenticity 
   * 
   * @var \Drupal\Core\Database\Connection
  */
  protected $connection;

  /** 
   * The access_unpublished Token getter for getting the current page's hash.
   * 
   * @var \Drupal\access_unpublished\TokenGetter
   */
  protected $tokenGetter;

  /** An array of hashes keyed by nodeID
   * 
   * @var array
   */
  protected $hashTable;

  /** 
   * An array of nodeIDs in the book, published or unpublished.
   * 
   * @var array
   */
  protected $bookNodeIds;

  /** 
   * {@inheritdoc}
   */
  public function __construct(TokenGetter $tokenGetter, Connection $connection, EntityTypeManagerInterface $entity_type_manager, TranslationInterface $translation, ConfigFactoryInterface $config_factory, BookOutlineStorageInterface $book_outline_storage, RendererInterface $renderer, LanguageManagerInterface $language_manager, EntityRepositoryInterface $entity_repository, CacheBackendInterface $backend_chained_cache, CacheBackendInterface $memory_cache) {
    $this->tokenGetter = $tokenGetter;
    $this->connection = $connection;

    parent::__construct( $entity_type_manager, $translation, $config_factory, $book_outline_storage, $renderer,  $language_manager, $entity_repository, $backend_chained_cache, $memory_cache);
  }

  /** 
   * Recursively get node IDs in the tree.
   * 
   * @param array $tree
   *   The full book tree.
   */
  protected function traverseBookNodeIds(array &$tree) {
    foreach ($tree as $key => $v) {
      if ($tree[$key]['below']) {
        $this
          ->traverseBookNodeIds($tree[$key]['below']);
      }
      $this->bookNodeIds[] = $key;
    }
  }

  /**
   * Load node IDs from tree and store in cache.
   * 
   * @param array $tree
   *   The full book tree.
   */
  protected function getBookNodeIds(array &$tree) {
    foreach ($tree as $key => $v) {
      // Run once for the parent book node:
      if ($tree[$key]["link"]["bid"] == $key) {
        $this->hashTable = [];
        $nid_cache_id = "book:nids:" . $key;
        $cache = $this->backendChainedCache->get($nid_cache_id);
        if (!$cache) {
          $this->bookNodeIds = [];
          $this->traverseBookNodeIds($tree);
          $cache = $this->backendChainedCache->set($nid_cache_id, $this->bookNodeIds);
        }
        else {
          $this->bookNodeIds = $cache->data;
        }
      }
    }
  }

  /**
   * Load the hashIds of each node in the tree and store in cache.
   * 
   * @param array $tree
   *   The full book tree.
   */
  protected function getHashTable(array &$tree) {
    foreach ($tree as $key => $v) {
      // Run once for the parent book node:
      if ($tree[$key]["link"]["bid"] == $key) {
        $hash_cache_id = "book:auHash:" . $key;
        $cache = $this->backendChainedCache->get($hash_cache_id);
        if (!$cache) {
          $query = $this->connection->query("
            SELECT entity_id,value FROM access_token
            WHERE entity_id IN ('" . implode("', '", $this->bookNodeIds) . "') AND (entity_type='node')
          ");
          $result = $query->fetchAllKeyed(0);
          if ($result) {
            $this->hashTable = $result;
            $cache = $this->backendChainedCache->set($hash_cache_id, $this->hashTable);
          }
        }
        else {
          $this->hashTable = $cache->data;
        }
      }
    }
  }

  /** 
   * {@inheritdoc}
   */
  protected function doBookTreeCheckAccess(&$tree) {
    $hash = $this->tokenGetter->getToken();
    $new_tree = [];

    // Load node IDs and hash table from cache or query them:
    if ($hash) {
      $this->getBookNodeIds($tree);
      $this->getHashTable($tree);
    }

    foreach ($tree as $key => $v) {
      $item =& $tree[$key]['link'];
      $this
        ->bookLinkTranslate($item);

      // Only allow hash access if we're viewing a node via hash access already:
      if ($hash) {
        // Check access_token table for unlimited tokens for the node:
        // If exists, output book link with the hash parameter:
        if ($this->hashTable && array_key_exists($key, $this->hashTable)) {
          $item['access'] = TRUE;
          $item['hash'] = $this->hashTable[$key];
        }
      }
      if ($item['access']) {
        if ($tree[$key]['below']) {
          $this
            ->doBookTreeCheckAccess($tree[$key]['below']);
        }

        // Maintain relative weights while ensuring keys are unique by adding nid
        // Adding title after weight sorts items with equal weight alphabetically.
        $new_tree[50000 + $item['weight'] . ' ' . $item['title'] . ' ' . $item['nid']] = $tree[$key];
      }
    }

    // Sort siblings in the tree based on weights and localized title.
    ksort($new_tree);
    $tree = $new_tree;
  }

  /**
   * {@inheritdoc}
   */
  public function bookLinkTranslate(&$link) {
    // Check access via api, since node_access query doesn't check
    // for unpublished nodes.
    // @todo load nodes en-mass rather than individually.
    // @see https://www.drupal.org/project/drupal/issues/2470896
    $node = $this->entityTypeManager->getStorage('node')->load($link['nid']);
    $hash = $this->tokenGetter->getToken();
    $link['access'] = FALSE;
    // Only allow hash access if we're viewing a node via hash access already:
    if ($hash) {
      // Check access_token table for unlimited tokens for the node:
      // If exists, output book link with the hash parameter:
      if ($this->hashTable && array_key_exists($link['nid'], $this->hashTable)) {
        $link['access'] = TRUE;
        $link['hash'] = $this->hashTable[$link['nid']];
      }
    }

    // If we don't have hash access, fall back on standard access check
    if (!$link['access']) {
      $link['access'] = $node && $node->access('view');
    }

    // For performance, only localize a link the user can access
    if ($link['access']) {
      $node = $this->entityRepository->getTranslationFromContext($node);
      $link['title'] = $node->label();
      $link['options'] = [];
    }
    return $link;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildItems(array $tree) {

    // Get the correct hash key for building link query params:
    $hashConfig = $this->configFactory->get('access_unpublished.settings');
    $hashKey = $hashConfig->get('hash_key');
    $items = parent::buildItems($tree);

    foreach ($items as $key => $item) {
      if ($this->hashTable && array_key_exists($key, $this->hashTable)) {
        $items[$key]['url']->setOption('query', [$hashKey => $this->hashTable[$key]]);
      }
    }

    return $items;
  }
}