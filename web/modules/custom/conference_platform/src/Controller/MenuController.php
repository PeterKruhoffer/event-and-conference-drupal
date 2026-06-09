<?php

namespace Drupal\conference_platform\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposes Drupal menus for the decoupled frontend.
 */
class MenuController extends ControllerBase {

  /**
   * Constructs a MenuController object.
   */
  public function __construct(
    protected MenuLinkTreeInterface $menuTree,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('menu.link_tree'),
    );
  }

  /**
   * Returns an enabled, access-checked menu tree.
   */
  public function tree(string $menu_name = 'main'): CacheableJsonResponse {
    $parameters = (new MenuTreeParameters())->onlyEnabledLinks();
    $tree = $this->menuTree->load($menu_name, $parameters);
    $tree = $this->menuTree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);

    $response = new CacheableJsonResponse([
      'menu' => $menu_name,
      'items' => $this->normalizeTree($tree),
    ]);

    $cacheability = (new CacheableMetadata())
      ->setCacheTags(["config:system.menu.$menu_name", 'menu_link_content_list'])
      ->setCacheContexts(['url.site', 'user.permissions'])
      ->setCacheMaxAge(Cache::PERMANENT);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Normalizes menu tree elements into JSON-safe arrays.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   Menu tree elements.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized menu items.
   */
  protected function normalizeTree(array $tree): array {
    $items = [];

    foreach ($tree as $element) {
      if ($element->access !== NULL && !$element->access->isAllowed()) {
        continue;
      }

      $url = $element->link->getUrlObject();
      $items[] = [
        'id' => $element->link->getPluginId(),
        'title' => (string) $element->link->getTitle(),
        'description' => (string) $element->link->getDescription(),
        'url' => $this->toHref($url),
        'external' => $url->isExternal(),
        'expanded' => $element->link->isExpanded(),
        'children' => $this->normalizeTree($element->subtree),
      ];
    }

    return $items;
  }

  /**
   * Converts a Drupal URL object to a frontend-friendly href.
   */
  protected function toHref(Url $url): string {
    if ($url->isRouted()) {
      return $url->toString();
    }

    $uri = $url->getUri();
    if (str_starts_with($uri, 'internal:')) {
      return substr($uri, strlen('internal:')) ?: '/';
    }

    return $url->toString();
  }

}
