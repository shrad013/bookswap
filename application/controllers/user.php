<?php

/**
 * NOTE: This class is an example implementation that is intended for testing
 * and development use only. It does NO password-checking and should be either
 * expanded or replaced with a more robust authentication library in production.
 */
class User extends BS_Controller {

  public function login() {
    if ( ! $this->user) {
      $user = $this->authenticate();
      if ($user) {
        $this->session->set_userdata('bookswap_user', $user);
      }
    }
    redirect($this->get_last_page());
  }

  public function logout() {
    $this->session->sess_destroy();
    redirect(base_url('index.php'));
  }

  public function authenticate() {
    $netid = $this->input->post('username', TRUE);
    if ( ! $netid) {
      return FALSE;
    }
    $user = $this->users->get_by(array('netid' => $netid));
    if ( ! $user) {
      $new_user = array(
        'netid' => $netid,
        'email' => 'john@example.edu',
        'first_name' => 'John',
      );
      $this->user_model->add_user($new_user);
      $user = $this->users->insert(array('netid' => $netid));
    }
    return $user;
  }

}
