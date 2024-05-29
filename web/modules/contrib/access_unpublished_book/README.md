# access_unpublished_book
Drupal module integrating access_unpublished with Book navigation

## Requirements

* Drupal.org/project/access_unpublished

## Accessing unpublished books via code

1. Replace instances of \Drupal\book\BookManager with \Drupal\access_unpublished_book\AccessUnpublishedManager
```
/** @var \Drupal\access_unpublished_book\AccessUnpublishedManager $book_manager */
$book_manager = \Drupal::service('access_unpublished_book.manager');
```

2. If you manually add book navigation tree links in templates, be sure to include `url.options`:

in book-tree.html.twig:
```
<a href="{{ path(item.url.routeName, item.url.routeParameters, item.url.options) }}">{{ item.title }}</a>
```