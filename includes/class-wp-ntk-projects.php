<?php
/** Регистрация проектов наставников. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WP_NTK_Projects {
 const TYPE = 'wp_ntk_project';
 const OPTION = 'wp_ntk_projects_config';
 private $errors = array();
 private $values = null;

 public function hooks() {
  add_action( 'init', array( $this, 'register_type' ) );
  add_action( 'template_redirect', array( $this, 'handle' ), 3 );
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_projects_settings', array( $this, 'save_settings' ) );
  add_action( 'admin_post_wp_ntk_project_manage', array( $this, 'manage_project' ) );
  add_action( 'admin_post_wp_ntk_remove_project_file', array( $this, 'remove_file' ) );
 }
 public function register_type() {
  register_post_type( self::TYPE, array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title', 'editor', 'author' ) ) );
 }
 private function settings() {
  $default = array( 'nominations' => array( '1' => 'Номинация 1', '2' => 'Номинация 2', '3' => 'Номинация 3', '4' => 'Номинация 4' ), 'start' => '', 'end' => '' );
  $c = get_option( self::OPTION, array() );
  return is_array( $c ) ? array_merge( $default, $c ) : $default;
 }
 /** Проверяет локальные дату и время, исключая несуществующие минуты. */
 private function date( $value ) {
  if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value ) ) { return false; }
  $d = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
  return $d && $d->format( 'Y-m-d\TH:i' ) === $value ? $d : false;
 }
 private function period() {
  $c = $this->settings(); $start = $this->date( $c['start'] ); $end = $this->date( $c['end'] );
  if ( ! $start || ! $end || $start >= $end ) { return array( 'state' => 'unset' ); }
  $now = time();
  return array( 'state' => $now < $start->getTimestamp() ? 'before' : ( $now >= $end->getTimestamp() ? 'after' : 'open' ), 'start' => $start, 'end' => $end );
 }
 /** Окончание приёма — основание закрыть регистрацию новых аккаунтов. */
 public function registration_period_ended() { return 'after' === $this->period()['state']; }
 public function can_register() {
  if ( ! is_user_logged_in() ) { return false; }
  $user = wp_get_current_user();
  return WP_NTK_Setup::is_mentor( $user ) || in_array( 'subscriber', (array) $user->roles, true ) || current_user_can( 'manage_options' );
 }
 private function post_value( $name ) { return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; }
 private function page_url() { return get_permalink( get_queried_object_id() ); }
 public function menu() { add_submenu_page( 'wp-ntk', 'Проекты', 'Проекты', 'manage_options', 'wp-ntk-projects', array( $this, 'admin_page' ) ); }
 public function save_settings() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.' ); }
  check_admin_referer( 'wp_ntk_projects_settings' );
  $names = array();
  for ( $i = 1; $i <= 4; $i++ ) {
   $name = sanitize_text_field( $this->post_value( 'nomination_' . $i ) );
   if ( '' === $name || WP_NTK_Fields::length( $name ) > 200 ) { wp_die( 'Заполните все четыре названия номинаций (до 200 символов каждое).' ); }
   $names[ (string) $i ] = $name;
  }
  $start = $this->post_value( 'start' ); $end = $this->post_value( 'end' );
  if ( '' !== $start || '' !== $end ) {
   $a = $this->date( $start ); $b = $this->date( $end );
   if ( ! $a || ! $b || $a >= $b ) { wp_die( 'Укажите корректные дату и время начала и окончания. Окончание должно быть позже начала.' ); }
  }
  update_option( self::OPTION, array( 'nominations' => $names, 'start' => $start, 'end' => $end ), false );
  do_action( 'wp_ntk_project_changed' );
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-projects&saved=1' ) ); exit;
 }
 /** Перемещение проекта в корзину и восстановление доступны только администраторам. */
 public function manage_project() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); }
  $raw_id = $this->post_value( 'project_id' );
  $operation = $this->post_value( 'operation' );
  if ( ! ctype_digit( $raw_id ) || (int) $raw_id <= 0 || ! in_array( $operation, array( 'trash', 'restore' ), true ) ) { wp_die( 'Некорректное действие.', '', array( 'response' => 400 ) ); }
  $id = (int) $raw_id;
  check_admin_referer( 'wp_ntk_project_' . $operation . '_' . $id );
  $post = get_post( $id );
  if ( ! $post || self::TYPE !== $post->post_type || ( 'trash' === $operation && 'private' !== $post->post_status ) || ( 'restore' === $operation && 'trash' !== $post->post_status ) ) { wp_die( 'Проект не найден или действие уже выполнено.', '', array( 'response' => 404 ) ); }
  if ( 'trash' === $operation ) {
   // При отключённой корзине WordPress удаляет данные сразу — не делаем этого неявно.
   if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) { wp_die( 'Корзина WordPress отключена. Включите её, чтобы удалять проекты с возможностью восстановления.' ); }
   $result = wp_trash_post( $id );
  } else {
   // WordPress по умолчанию восстанавливает записи в черновик; проекты должны снова стать закрытыми.
   $restore_private = function( $status, $post_id ) use ( $id ) { return (int) $post_id === $id ? 'private' : $status; };
   add_filter( 'wp_untrash_post_status', $restore_private, 10, 2 );
   $result = wp_untrash_post( $id );
   remove_filter( 'wp_untrash_post_status', $restore_private, 10 );
  }
  if ( ! $result || is_wp_error( $result ) ) { wp_die( 'Не удалось изменить состояние проекта. Попробуйте ещё раз.' ); }
  do_action( 'wp_ntk_project_changed' );
  wp_safe_redirect( add_query_arg( array( 'page' => 'wp-ntk-projects', 'view' => 'trash' === $operation ? 'active' : 'trash', 'project_result' => 'trash' === $operation ? 'trashed' : 'restored' ), admin_url( 'admin.php' ) ) . '#wp-ntk-admin-projects' ); exit;
 }
 /** Отдельная отправка удаляет только файл и не сохраняет остальные поля проекта. */
 public function remove_file() {
  if ( ! $this->can_register() ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); }
  $raw_id = $this->post_value( 'project_id' ); $key = $this->post_value( 'section' ); $blob = $this->post_value( 'blob' );
  if ( ! ctype_digit( $raw_id ) || (int) $raw_id <= 0 || ! preg_match( '/^f_[a-f0-9]{12}$/D', $key ) || ! preg_match( '/^[a-f0-9]{40}\\.bin$/D', $blob ) ) { wp_die( 'Некорректные данные.', '', array( 'response' => 400 ) ); }
  $id = (int) $raw_id; check_admin_referer( 'wp_ntk_remove_file_' . $id . '_' . $key . '_' . $blob );
  $post = get_post( $id );
  if ( ! $post || self::TYPE !== $post->post_type || 'private' !== $post->post_status || (int) $post->post_author !== get_current_user_id() ) { wp_die( 'Проект не найден или недоступен.', '', array( 'response' => 403 ) ); }
  $result = 'open' === $this->period()['state'] ? WP_NTK_Project_Files::delete_one( $id, $key, $blob ) : 'closed';
  $redirect = wp_validate_redirect( $this->post_value( 'return_url' ), home_url( '/' ) );
  $redirect = remove_query_arg( array( 'ntk_project_saved', 'ntk_project_view', 'ntk_file_result' ), $redirect );
  wp_safe_redirect( add_query_arg( array( 'wp_ntk_tab' => 'projects', 'ntk_project_form' => $id, 'ntk_file_result' => $result ), $redirect ) . '#wp-ntk-projects' ); exit;
 }
 public function admin_page() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.' ); }
  $c = $this->settings(); $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
  $view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) && 'trash' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'trash' : 'active';
  $q = new WP_Query( array( 'post_type' => self::TYPE, 'post_status' => 'trash' === $view ? 'trash' : 'private', 'posts_per_page' => 20, 'paged' => $paged, 'orderby' => 'ID', 'order' => 'DESC' ) );
  ?>
  <div class="wrap"><h1>Регистрация проектов</h1>
  <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Настройки сохранены.</p></div><?php endif; ?>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wp_ntk_projects_settings"><?php wp_nonce_field( 'wp_ntk_projects_settings' ); ?>
  <h2>Номинации</h2><p>Изменение названия меняет отображаемое название у всех ранее поданных проектов этой номинации.</p>
  <?php for ( $i = 1; $i <= 4; $i++ ) : ?><p><label>Номинация <?php echo (int) $i; ?> <input type="text" class="regular-text" maxlength="200" required name="nomination_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $c['nominations'][ (string) $i ] ); ?>"></label></p><?php endfor; ?>
  <h2>Период приёма</h2><p>Время сайта: <?php echo esc_html( wp_timezone_string() ); ?>. Начало включительно, окончание — момент закрытия. Если обе даты пусты, приём закрыт. После окончания этого периода регистрация новых пользователей закрывается автоматически. Продление срока снова откроет регистрацию, если она не закрыта вручную.</p>
  <p><label>Начало <input type="datetime-local" name="start" value="<?php echo esc_attr( $c['start'] ); ?>"></label> <label>Окончание <input type="datetime-local" name="end" value="<?php echo esc_attr( $c['end'] ); ?>"></label></p>
  <?php submit_button( 'Сохранить настройки' ); ?></form>
  <h2 id="wp-ntk-admin-projects">Проекты</h2>
  <?php if ( isset( $_GET['project_result'] ) && 'trashed' === $_GET['project_result'] ) : ?><div class="notice notice-success"><p>Проект перемещён в корзину.</p></div><?php elseif ( isset( $_GET['project_result'] ) && 'restored' === $_GET['project_result'] ) : ?><div class="notice notice-success"><p>Проект восстановлен.</p></div><?php endif; ?>
  <p><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ntk-projects', 'view' => 'active' ), admin_url( 'admin.php' ) ) . '#wp-ntk-admin-projects' ); ?>">Все проекты</a> | <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ntk-projects', 'view' => 'trash' ), admin_url( 'admin.php' ) ) . '#wp-ntk-admin-projects' ); ?>">Корзина</a></p>
  <p><?php echo 'trash' === $view ? 'В корзине: ' : 'Всего проектов: '; echo (int) $q->found_posts; ?>. Удалённые проекты хранятся в корзине WordPress и могут быть автоматически удалены по её настройкам.</p>
  <table class="widefat striped"><thead><tr><th>Проект и описание</th><th>Номинация</th><th>Наставник</th><th>Дата</th><th>Действие</th></tr></thead><tbody>
  <?php foreach ( $q->posts as $p ) : $mentor = get_userdata( $p->post_author ); $nom = (string) get_post_meta( $p->ID, '_wp_ntk_nomination', true ); ?><tr><td><strong><?php echo esc_html( $p->post_title ); ?></strong><br><?php echo nl2br( esc_html( $p->post_content ) ); ?><?php $attachments = WP_NTK_Project_Files::files( $p->ID ); if ( $attachments ) : ?><ul><?php foreach ( $attachments as $key => $file ) : ?><li><?php echo esc_html( WP_NTK_Project_Files::section_label( $key, $file ) ); ?>: <a href="<?php echo esc_url( WP_NTK_Project_Files::url( $p->ID, $key ) ); ?>"><?php echo esc_html( $file['name'] ); ?></a></li><?php endforeach; ?></ul><?php endif; ?></td><td><?php echo esc_html( isset( $c['nominations'][$nom] ) ? $c['nominations'][$nom] : 'Неизвестно' ); ?></td><td><?php if ( $mentor ) : ?><a href="<?php echo esc_url( get_edit_user_link( $mentor->ID ) ); ?>"><?php echo esc_html( $mentor->display_name ); ?></a><?php else : ?>Пользователь удалён<?php endif; ?></td><td><?php echo esc_html( $p->post_date ); ?></td><td>
  <?php $operation = 'trash' === $view ? 'restore' : 'trash'; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php if ( 'trash' === $operation ) : ?> onsubmit="return confirm('Переместить проект в корзину?');"<?php endif; ?>>
  <input type="hidden" name="action" value="wp_ntk_project_manage"><input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>"><input type="hidden" name="project_id" value="<?php echo (int) $p->ID; ?>"><?php wp_nonce_field( 'wp_ntk_project_' . $operation . '_' . $p->ID ); ?><button class="button" type="submit"><?php echo 'trash' === $operation ? 'Удалить' : 'Восстановить'; ?></button></form>
  </td></tr><?php endforeach; ?>
  <?php if ( ! $q->posts ) : ?><tr><td colspan="5">Проектов пока нет.</td></tr><?php endif; ?></tbody></table>
  <?php if ( $q->max_num_pages > 1 ) : ?><p><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $q->max_num_pages ) ) ); ?></p><?php endif; ?>
  </div><?php
 }
 public function handle() {
  // PHP очищает POST и FILES целиком, если запрос превышает post_max_size.
  if ( 'POST' === $_SERVER['REQUEST_METHOD'] && empty( $_POST ) && empty( $_FILES ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) && is_singular() && has_shortcode( get_post_field( 'post_content', get_queried_object_id() ), WP_NTK_Setup::SHORTCODE ) && isset( $_GET['ntk_project_form'] ) ) {
   $limit = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
   if ( $limit > 0 && (int) $_SERVER['CONTENT_LENGTH'] > $limit ) { $this->errors[] = 'Общий размер отправленных файлов превышает ограничение сервера. Уменьшите размер файлов или обратитесь к администратору.'; }
   return;
  }
  if ( 'save' !== $this->post_value( 'wp_ntk_project_action' ) ) { return; }
  if ( ! is_singular() || ! has_shortcode( get_post_field( 'post_content', get_queried_object_id() ), WP_NTK_Setup::SHORTCODE ) ) { return; }
  nocache_headers();
  if ( ! $this->can_register() ) { wp_die( 'Регистрация проекта доступна наставнику, подписчику или администратору.', '', array( 'response' => 403 ) ); }
  $id = absint( $this->post_value( 'wp_ntk_project_id' ) );
  $this->values = array( 'id' => $id, 'title' => sanitize_text_field( $this->post_value( 'wp_ntk_project_title' ) ), 'description' => sanitize_textarea_field( $this->post_value( 'wp_ntk_project_description' ) ), 'nomination' => $this->post_value( 'wp_ntk_project_nomination' ) );
  if ( ! wp_verify_nonce( $this->post_value( 'wp_ntk_project_nonce' ), 'wp_ntk_project_save' ) ) { $this->errors[] = 'Срок действия формы истёк. Обновите страницу и попробуйте снова.'; return; }
  if ( 'open' !== $this->period()['state'] ) { $this->errors[] = 'Приём проектов сейчас закрыт.'; return; }
  if ( $id ) { $p = get_post( $id ); if ( ! $p || self::TYPE !== $p->post_type || 'private' !== $p->post_status || (int) $p->post_author !== get_current_user_id() ) { wp_die( 'Проект не найден или недоступен.', '', array( 'response' => 403 ) ); } }
  $v = $this->values; $c = $this->settings();
  if ( '' === $v['title'] || WP_NTK_Fields::length( $v['title'] ) > 200 ) { $this->errors[] = 'Укажите название проекта (до 200 символов).'; }
  if ( '' === $v['description'] || WP_NTK_Fields::length( $v['description'] ) > 5000 ) { $this->errors[] = 'Укажите описание проекта (до 5000 символов).'; }
  if ( ! in_array( $v['nomination'], array( '1', '2', '3', '4' ), true ) || empty( $c['nominations'][$v['nomination']] ) ) { $this->errors[] = 'Выберите номинацию.'; }
  if ( $this->errors ) { return; }
  $prepared = WP_NTK_Project_Files::prepare( $id );
  if ( $prepared['errors'] ) { $this->errors = array_merge( $this->errors, $prepared['errors'] ); return; }
  $previous = $id ? get_post( $id ) : null;
  $args = array( 'post_type' => self::TYPE, 'post_status' => 'private', 'post_title' => wp_slash( $v['title'] ), 'post_content' => wp_slash( $v['description'] ), 'post_author' => get_current_user_id() );
  if ( $id ) { $args['ID'] = $id; }
  $saved = wp_insert_post( $args, true );
  if ( is_wp_error( $saved ) ) { WP_NTK_Project_Files::rollback( $prepared['new'] ); $this->errors[] = 'Не удалось сохранить проект. Попробуйте ещё раз.'; return; }
  if ( ! WP_NTK_Project_Files::commit( $saved, $prepared ) ) {
   if ( $previous ) { wp_update_post( array( 'ID' => $previous->ID, 'post_title' => wp_slash( $previous->post_title ), 'post_content' => wp_slash( $previous->post_content ) ) ); }
   else { wp_delete_post( $saved, true ); }
   $this->errors[] = 'Не удалось сохранить данные файлов. Попробуйте ещё раз.'; return;
  }
  update_post_meta( $saved, '_wp_ntk_nomination', $v['nomination'] );
  do_action( 'wp_ntk_project_changed' );
  wp_safe_redirect( add_query_arg( array( 'wp_ntk_tab' => 'projects', 'ntk_project_saved' => '1' ), $this->page_url() ) . '#wp-ntk-projects' ); exit;
 }
 public function render() {
  if ( ! $this->can_register() ) { return; }
  $period = $this->period(); $c = $this->settings(); $url = add_query_arg( 'wp_ntk_tab', 'projects', $this->page_url() );
  $paged = isset( $_GET['ntk_projects_page'] ) ? max( 1, absint( $_GET['ntk_projects_page'] ) ) : 1;
  $q = new WP_Query( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'author' => get_current_user_id(), 'posts_per_page' => 20, 'paged' => $paged, 'orderby' => 'ID', 'order' => 'DESC' ) );
  echo '<section id="wp-ntk-projects"><h2 class="wp-ntk-title">Проекты</h2>';
  if ( isset( $_GET['ntk_project_saved'] ) ) { echo '<div class="wp-ntk-notice wp-ntk-notice--success">Проект сохранён.</div>'; }
  $file_result = isset( $_GET['ntk_file_result'] ) && is_string( $_GET['ntk_file_result'] ) ? sanitize_key( wp_unslash( $_GET['ntk_file_result'] ) ) : '';
  $file_messages = array( 'deleted' => 'Файл удалён.', 'cleanup' => 'Файл исключён из проекта, но копию в хранилище удалить не удалось. Обратитесь к администратору.', 'required' => 'Файл обязательного раздела нельзя удалить отдельно. Чтобы заменить его, выберите другой файл и сохраните проект.', 'storage' => 'Хранилище файла недоступно. Файл не удалён.', 'changed' => 'Файл уже изменился или данные устарели. Обновите страницу и попробуйте снова.', 'unavailable' => 'Раздел недоступен. Файл не удалён.', 'closed' => 'Приём завершён или ещё не открыт. Изменять файлы нельзя.' );
  if ( isset( $file_messages[$file_result] ) ) { echo '<div class="wp-ntk-notice wp-ntk-notice--' . ( 'deleted' === $file_result ? 'success' : 'error' ) . '" role="status">' . esc_html( $file_messages[$file_result] ) . '</div>'; }
  if ( 'unset' === $period['state'] ) { echo '<p>Период регистрации проектов ещё не настроен.</p>'; }
  else {
   if ( 'open' === $period['state'] ) { echo '<p>Приём открыт. <span class="wp-ntk-countdown" data-end="' . esc_attr( $period['end']->getTimestamp() ) . '" data-now="' . esc_attr( time() ) . '"></span></p>'; }
   elseif ( 'before' === $period['state'] ) { echo '<p>Приём ещё не начался.</p>'; } else { echo '<p>Приём завершён.</p>'; }
  }
  if ( 'open' === $period['state'] ) { echo '<p><a class="wp-ntk-button" href="' . esc_url( add_query_arg( 'ntk_project_form', 'new', $url ) . '#wp-ntk-project-form' ) . '">Зарегистрировать проект</a></p>'; }
  else { echo '<p><button class="wp-ntk-button wp-ntk-button--disabled" type="button" disabled>Зарегистрировать проект</button></p>'; if ( current_user_can( 'manage_options' ) ) { echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wp-ntk-projects' ) ) . '">Настроить период приёма</a></p>'; } }
  if ( $q->posts ) {
   echo '<ul>'; foreach ( $q->posts as $p ) { $nom = (string) get_post_meta( $p->ID, '_wp_ntk_nomination', true ); echo '<li><strong>' . esc_html( $p->post_title ) . '</strong> — ' . esc_html( isset( $c['nominations'][$nom] ) ? $c['nominations'][$nom] : 'Номинация не указана' ); echo ' — <a href="' . esc_url( add_query_arg( 'ntk_project_view', $p->ID, $url ) . '#wp-ntk-project-view' ) . '">Просмотреть</a>'; if ( 'open' === $period['state'] ) { echo ' — <a href="' . esc_url( add_query_arg( 'ntk_project_form', $p->ID, $url ) . '#wp-ntk-project-form' ) . '">Изменить</a>'; } echo '</li>'; } echo '</ul>';
   if ( $q->max_num_pages > 1 ) { echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'ntk_projects_page', '%#%', $url ) . '#wp-ntk-projects', 'format' => '', 'current' => $paged, 'total' => $q->max_num_pages ) ) ); }
  } else { echo '<p>У вас пока нет проектов.</p>'; }
  $view_id = isset( $_GET['ntk_project_view'] ) && is_string( $_GET['ntk_project_view'] ) ? wp_unslash( $_GET['ntk_project_view'] ) : '';
  if ( '' !== $view_id ) {
   $project = ctype_digit( $view_id ) && (int) $view_id > 0 ? get_post( (int) $view_id ) : null;
   if ( $project && self::TYPE === $project->post_type && 'private' === $project->post_status && (int) $project->post_author === get_current_user_id() ) {
    $nomination = (string) get_post_meta( $project->ID, '_wp_ntk_nomination', true );
    echo '<section class="wp-ntk-project-view" id="wp-ntk-project-view"><h3>Просмотр проекта</h3>';
    echo '<p><strong>Номинация:</strong> ' . esc_html( isset( $c['nominations'][$nomination] ) ? $c['nominations'][$nomination] : 'Номинация не указана' ) . '</p>';
    echo '<p><strong>Название проекта:</strong> ' . esc_html( $project->post_title ) . '</p>';
    echo '<p><strong>Описание проекта:</strong><br>' . nl2br( esc_html( $project->post_content ) ) . '</p>';
    $attachments = WP_NTK_Project_Files::files( $project->ID );
    if ( $attachments ) { echo '<h4>Файлы</h4><ul>'; foreach ( $attachments as $key => $file ) { echo '<li>' . esc_html( WP_NTK_Project_Files::section_label( $key, $file ) ) . ': <a href="' . esc_url( WP_NTK_Project_Files::url( $project->ID, $key ) ) . '">' . esc_html( $file['name'] ) . '</a></li>'; } echo '</ul>'; }
    if ( 'open' === $period['state'] ) { echo '<p><a href="' . esc_url( add_query_arg( 'ntk_project_form', $project->ID, $url ) . '#wp-ntk-project-form' ) . '">Изменить проект</a></p>'; }
    echo '<p><a href="' . esc_url( $url . '#wp-ntk-projects' ) . '">Закрыть просмотр</a></p></section>';
   } else { echo '<div class="wp-ntk-notice wp-ntk-notice--error" role="alert">Проект не найден или недоступен.</div>'; }
  }
  foreach ( $this->errors as $error ) { echo '<div class="wp-ntk-notice wp-ntk-notice--error" role="alert">' . esc_html( $error ) . '</div>'; }
  $form = isset( $_GET['ntk_project_form'] ) && is_string( $_GET['ntk_project_form'] ) ? sanitize_text_field( wp_unslash( $_GET['ntk_project_form'] ) ) : '';
  $v = $this->values;
  if ( ! $v && 'new' === $form ) { $v = array( 'id' => 0, 'title' => '', 'description' => '', 'nomination' => '' ); }
  elseif ( ! $v && ctype_digit( $form ) ) { $p = get_post( (int) $form ); if ( $p && self::TYPE === $p->post_type && 'private' === $p->post_status && (int) $p->post_author === get_current_user_id() ) { $v = array( 'id' => $p->ID, 'title' => $p->post_title, 'description' => $p->post_content, 'nomination' => (string) get_post_meta( $p->ID, '_wp_ntk_nomination', true ) ); } }
  if ( $v && 'open' === $period['state'] ) {
   echo '<h3>' . ( $v['id'] ? 'Изменить проект' : 'Регистрация проекта' ) . '</h3><form class="wp-ntk-form" id="wp-ntk-project-form" method="post" enctype="multipart/form-data" action="' . esc_url( add_query_arg( 'ntk_project_form', $v['id'] ? $v['id'] : 'new', $url ) . '#wp-ntk-projects' ) . '">';
   echo '<input type="hidden" name="wp_ntk_project_action" value="save"><input type="hidden" name="wp_ntk_project_id" value="' . (int) $v['id'] . '">'; wp_nonce_field( 'wp_ntk_project_save', 'wp_ntk_project_nonce' );
   echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="ntk-project-nomination">Номинация *</label><select class="wp-ntk-input" id="ntk-project-nomination" name="wp_ntk_project_nomination" required><option value="">Выберите номинацию</option>';
   foreach ( $c['nominations'] as $id => $name ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( (string) $id, $v['nomination'], false ) . '>' . esc_html( $name ) . '</option>'; }
   echo '</select></div>';
   echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="ntk-project-title">Название проекта *</label><input class="wp-ntk-input" id="ntk-project-title" name="wp_ntk_project_title" maxlength="200" required value="' . esc_attr( $v['title'] ) . '"></div>';
   echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="ntk-project-description">Описание проекта *</label><textarea class="wp-ntk-input" id="ntk-project-description" name="wp_ntk_project_description" maxlength="5000" rows="5" required>' . esc_textarea( $v['description'] ) . '</textarea></div>';
   $uploaded = WP_NTK_Project_Files::files( $v['id'] );
   foreach ( WP_NTK_Project_Files::sections() as $key => $section ) {
    if ( empty( $section['active'] ) ) { continue; }
    $existing = isset( $uploaded[$key] ) ? $uploaded[$key] : null;
    $accept = array(); foreach ( $section['types'] as $type ) { $accept[] = '.' . $type; if ( 'jpg' === $type ) { $accept[] = '.jpeg'; } }
    echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="wp-ntk-file-' . esc_attr( $key ) . '">' . esc_html( $section['name'] ) . ( ! empty( $section['required'] ) ? ' *' : '' ) . '</label>';
    echo '<p class="wp-ntk-hint">Форматы: ' . esc_html( implode( ', ', $section['types'] ) ) . '; до ' . (int) $section['max'] . ' МБ. Один файл.</p>';
    if ( $existing ) { echo '<p>Текущий файл: <a href="' . esc_url( WP_NTK_Project_Files::url( $v['id'], $key ) ) . '">' . esc_html( $existing['name'] ) . '</a></p>'; }
    echo '<input class="wp-ntk-input wp-ntk-project-file" type="file" id="wp-ntk-file-' . esc_attr( $key ) . '" name="wp_ntk_attachment[' . esc_attr( $key ) . ']" accept="' . esc_attr( implode( ',', $accept ) ) . '" aria-describedby="wp-ntk-file-error-' . esc_attr( $key ) . '"' . ( ! empty( $section['required'] ) && ! $existing ? ' required' : '' ) . '><p class="wp-ntk-file-error" id="wp-ntk-file-error-' . esc_attr( $key ) . '" role="alert"></p>';
    if ( $existing ) {
     if ( ! empty( $section['required'] ) ) { echo '<p><button type="button" class="wp-ntk-button wp-ntk-button--disabled" disabled>Удалить текущий файл</button><br><small>Файл обязателен. Чтобы заменить его, выберите новый и сохраните проект.</small></p>'; }
     else { echo '<p><button type="submit" class="wp-ntk-button" form="wp-ntk-remove-' . esc_attr( $key ) . '" onclick="return confirm(&quot;Удалить файл сейчас? Несохранённые изменения проекта будут потеряны.&quot;);">Удалить текущий файл</button><br><small>Копия в выгрузке будет удалена при следующей синхронизации.</small></p>'; }
    }
    echo '</div>';
   }
   echo '<button class="wp-ntk-button" type="submit">Сохранить проект</button></form>';
   foreach ( WP_NTK_Project_Files::sections() as $key => $section ) {
    if ( empty( $section['active'] ) || ! empty( $section['required'] ) || empty( $uploaded[$key]['blob'] ) ) { continue; }
    $blob = $uploaded[$key]['blob'];
    echo '<form id="wp-ntk-remove-' . esc_attr( $key ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_remove_project_file"><input type="hidden" name="project_id" value="' . (int) $v['id'] . '"><input type="hidden" name="section" value="' . esc_attr( $key ) . '"><input type="hidden" name="blob" value="' . esc_attr( $blob ) . '"><input type="hidden" name="return_url" value="' . esc_url( $this->page_url() ) . '">';
    wp_nonce_field( 'wp_ntk_remove_file_' . $v['id'] . '_' . $key . '_' . $blob ); echo '</form>';
   }
  }
  echo '</section>';
 }
}
