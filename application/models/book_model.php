<?php

class Book_model extends BS_Model {

  protected $primary_key = 'bid';

  // Surprisingly, some books listed on bookstore websites DO NOT have ISBNs,
  // but all books DO have a bookstore product ID. We also require books to have
  // a title so they can be displayed meaningfully in the UI.
  protected $required_columns = array(
    'title',
    'bookstore_id',
  );

  /**
   * Values for the "required_status" column.
   */
  const BOOKSTORE_RECOMMENDED = 0;
  const GO_TO_CLASS_FIRST = 1;
  const RECOMMENDED = 2;
  const REQUIRED = 3;

  /**
   * Values for the "product_type" column.
   */
  const BOOK = 0;
  const PACKAGE_COMPONENT = 1;
  const PACKAGE = 2;

  public function __construct() {
    parent::__construct();
    $this->load->model('post_model', 'posts');
    $this->load->model('user_model', 'users');
  }

  public function prepare_entity(&$book) {
    $book->user_pid = NULL;
    $book->courses = array(
      'Bookstore Recommended' => array(),
      'Go To Class First' => array(),
      'Recommended' => array(),
      'Required' => array(),
    );
    $courses = $this->get_courses($book->bid);
    foreach($courses as $course) {
      switch ($course->required_status) {
        case self::BOOKSTORE_RECOMMENDED:
          $book->courses['Bookstore Recommended'][$course->name][] = $course->section;
          break;
        case self::GO_TO_CLASS_FIRST:
          $book->courses['Go To Class First'][$course->name][] = $course->section;
          break;
        case self::RECOMMENDED:
          $book->courses['Recommended'][$course->name][] = $course->section;
          break;
        case self::REQUIRED:
          $book->courses['Required'][$course->name][] = $course->section;
          break;
      }
    }

    $this->posts->order_by('price', 'asc');
    $book->posts = $this->posts->get_many_by(array(
      'bid' => $book->bid,
      'active' => TRUE,
    ));
    foreach ($book->posts as $post) {
      $post->user = $this->users->get($post->uid);
      if ($this->user && ($post->user->uid == $this->user->uid)) {
        $book->user_pid = $post->pid;
      }
    }

    $book->num_posts = count($book->posts);
    $book->min_student_price = $this->posts->get_min_price($book->bid);

    $book->num_store_offers = 0;
    $all_store_offers = array();
    $bookstore_offers = array();
    if ($book->bookstore_new_price) {
      $book->num_store_offers++;
      $all_store_offers[] = $bookstore_offers[] = $book->bookstore_new_price;
    }
    if ($book->bookstore_used_price) {
      $all_store_offers[] = $bookstore_offers[] = $book->bookstore_used_price;
    }
    if ($book->amazon_new_price) {
      $book->num_store_offers++;
      $all_store_offers[] = $book->amazon_new_price;
    }
    $book->min_store_price = ($all_store_offers) ? min($all_store_offers) : NULL;
    $book->min_bookstore_price = ($bookstore_offers) ? min($bookstore_offers) : NULL;
  }

  private function get_courses($bid) {
    $this->db->select('name, sections.code as section, sections_books.required_status');
    $this->db->distinct();
    $this->db->from('courses');
    $this->db->join('sections', 'sections.cid = courses.cid');
    $this->db->join('sections_books', 'sections_books.sid = sections.sid');
    $this->db->where('sections_books.bid', $bid);
    $this->db->order_by('name', 'ASC');
    $this->db->order_by('section', 'ASC');
    $query = $this->db->get();
    return $query->result();
  }

  /**
   * Retrieves books from the database that match the given string.
   *
   * @param string $string The string to search by
   * @return array Array of book objects
   */
  public function get_books_by_string($string) {
    $this->db->like('name', $string);
    $query = $this->db->get('courses');
    $courses = $query->result();
    if (count($courses) > 0) {
      $this->db->select('books.*');
      $this->db->distinct();
      $this->db->join('sections_books', 'sections_books.bid = books.bid');
      $this->db->join('sections', 'sections.sid = sections_books.sid');
      $this->db->where('sections.cid', $courses[0]->cid);
      return $this->get_all();
    }
    else {
      $this->db->or_like('title', $string);
      return $this->get_all();
    }
  }

  /**
   * Returns the ISBNs of books whose Amazon data needs to be updated.
   *
   * @return array Array of ISBN strings (at most 10)
   */
  public function get_books_to_update() {
    $this->db->select('isbn');
    $this->db->where('isbn IS NOT NULL');
    $one_day_ago = time() - (24 * 60 * 60);
    $this->db->where("(UNIX_TIMESTAMP(amazon_updated) < $one_day_ago OR amazon_updated IS NULL)");
    $this->db->order_by('amazon_updated', 'asc');
    $this->db->limit(10);
    $query = $this->db->get($this->table);
    $results = $query->result();
    $isbns = array();
    foreach ($results as $result) {
      if ($result->isbn) {
        $isbns[] = $result->isbn;
      }
    }
    return $isbns;
  }

  /**
   * Batch updates Amazon data for multiple books in the database.
   *
   * At minimum, each object in $book_details must specify the ISBN of the
   * book to update.
   *
   * @param array $book_details Array of book details to save to the database
   * @return int affected_rows() Number of rows updated, or false on error
   */
  public function update_amazon_data($book_details) {
    foreach ($book_details as &$book) {
      $book['amazon_updated'] = date('Y-m-d H:i:s');
    }
    return $this->update_many($book_details, 'isbn');
  }

  /**
   * Scrapes books from the bookstore website for the given sections.
   *
   * @see Section_model::scrape_children()
   *
   * @param array $section_ids Array of bookstore IDs for the sections to scrape
   * @return array Array of book objects cast as arrays
   */
  public function scrape($section_ids) {
    return $this->bookstore->get_books($section_ids);
  }

  /**
   * Saves scraped books to the database.
   *
   * @see Section_model::scrape_children()
   *
   * @param array $scraped_entities The scraped book arrays to save
   * @return array The books that are saved in the database,
   *               including their new or existing "bid" values
   */
  public function save_scraped_entities($scraped_entities) {
    // Fetch books that match the product IDs of the scraped books.
    $scraped_ids = array();
    $scraped_isbns = array();
    foreach ($scraped_entities as $book)  {
      $scraped_ids[] = $book['bookstore_id'];
      if (isset($book['isbn'])) {
        $scraped_isbns[] = $book['isbn'];
      }
    }
    $existing_books_by_id = array();
    if ($scraped_ids) {
      $existing_books_by_id = $this->with_result_key('bookstore_id')
                             ->get_many_by(array('bookstore_id' => $scraped_ids));
    }
    $existing_books_by_isbn = array();
    if ($scraped_isbns) {
      $existing_books_by_isbn = $this->with_result_key('isbn')
                             ->get_many_by(array('isbn' => $scraped_isbns));
    }

    $processed_ids = array();
    $saved_books = array();
    foreach ($scraped_entities as $book) {
      $product_id = $book['bookstore_id'];
      $existing_book = NULL;
      if (isset($existing_books_by_id[$product_id])) {
        $existing_book = $existing_books_by_id[$product_id];
      }
      else if (isset($book['isbn']) && isset($existing_books_by_isbn[$book['isbn']])) {
        $existing_book = $existing_books_by_isbn[$book['isbn']];
      }
      if ($existing_book) {
        $book['bid'] = $existing_book->bid;
        $saved_books[] = (object)$book;

        // If this is the first time we have processed this book during
        // this scrape operation, update its bookstore data in the database.
        if ( ! isset($processed_ids[$product_id])) {
          $book_prices = array(
            'bid' => $book['bid'],
            'bookstore_id' => $book['bookstore_id'],
            'bookstore_part_number' => $book['bookstore_part_number'],
            'bookstore_used_price' => $book['bookstore_used_price'],
            'bookstore_new_price' => $book['bookstore_new_price'],
            'updated' => date('Y-m-d h:i:s'),
          );
          $this->update($book_prices);
          $processed_ids[$product_id] = TRUE;
        }
      }
      else {
        $bid = $this->insert($book);
        if ($bid) {
          $book['bid'] = $bid;
          $saved_books[] = (object)$book;

          // Mark this book as being processed and add it to $existing_books
          // to prevent it from being inserted / updated again during this
          // scrape operation.
          $existing_books_by_id[$product_id] = (object)$book;
          $processed_ids[$product_id] = TRUE;
        }
      }
    }

    return $saved_books;
  }

}
