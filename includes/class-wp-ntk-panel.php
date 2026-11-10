<?php
/** Панель управления плагином. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WP_NTK_Panel {
 const CLOSED_OPTION = 'wp_ntk_registration_closed';
 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ) );
  add_action( 'admin_post_wp_ntk_settings', array( $this, 'save' ) );
  add_action( 'admin_post_wp_ntk_export', array( $this, 'export' ) );
 }
 public function menu() {
  add_menu_page( 'WP NTK', 'WP NTK', 'manage_options', 'wp-ntk', array( $this, 'page' ), 'dashicons-groups' );
 }
 public function save() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Недостаточно прав.', 'wp-ntk' ) ); }
  check_admin_referer( 'wp_ntk_settings' );
  update_option( self::CLOSED_OPTION, empty( $_POST['wp_ntk_closed'] ) ? '0' : '1' );
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk&updated=1' ) ); exit;
 }
 public function export() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Недостаточно прав.', 'wp-ntk' ) ); }
  check_admin_referer( 'wp_ntk_export' );
  nocache_headers();
  header( 'Content-Type: text/csv; charset=UTF-8' );
  header( 'Content-Disposition: attachment; filename="wp-ntk-mentors.csv"' );
  $out = fopen( 'php://output', 'w' );
  echo "\xEF\xBB\xBF";
  $fields = WP_NTK_Fields::all();
  $headers = array( 'ID', 'Email аккаунта', 'Дата регистрации' );
  foreach ( $fields as $field ) { $headers[] = $field['label']; }
  fputcsv( $out, $headers, ';' );
  $offset = 0;
  do {
   $users = get_users( array( 'role' => WP_NTK_Setup::ROLE, 'number' => 200, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC' ) );
   foreach ( $users as $user ) {
    $row = array( $user->ID, $user->user_email, $user->user_registered );
    foreach ( WP_NTK_Fields::get_values( $user->ID ) as $value ) { $row[] = $value; }
    // Excel может выполнить значения, начинающиеся с формулы.
    foreach ( $row as &$cell ) { $cell = (string) $cell; if ( preg_match( '/^[\s\x00-\x1F]*[=+\-@]/u', $cell ) ) { $cell = "'" . $cell; } } unset( $cell );
    fputcsv( $out, $row, ';' );
   }
   $offset += count( $users );
  } while ( count( $users ) === 200 );
  fclose( $out ); exit;
 }
 public function page() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Недостаточно прав.', 'wp-ntk' ) ); }
  $search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
  $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
  $args = array( 'role' => WP_NTK_Setup::ROLE, 'number' => 20, 'paged' => $paged, 'orderby' => 'ID', 'order' => 'DESC', 'count_total' => true );
  if ( $search ) { $args['search'] = '*' . $search . '*'; $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' ); }
  $query = new WP_User_Query( $args );
  $page_id = WP_NTK_Setup::account_page_id();
  ?>
  <div class="wrap"><h1>WP NTK — управление</h1>
  <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div><?php endif; ?>
  <h2>Личный кабинет</h2>
  <?php if ( $page_id ) : ?><p><a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>">Открыть страницу кабинета</a> | <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>">Редактировать страницу</a></p><?php else : ?><p>Опубликованная страница кабинета не найдена. Добавьте шорткод <code>[wp_ntk_account]</code> на страницу.</p><?php endif; ?>
  <h2>Регистрация</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
  <input type="hidden" name="action" value="wp_ntk_settings"><?php wp_nonce_field( 'wp_ntk_settings' ); ?>
  <p><label><input type="checkbox" name="wp_ntk_closed" value="1" <?php checked( get_option( self::CLOSED_OPTION ), '1' ); ?>> Закрыть регистрацию новых наставников</label></p><p class="description">Вход существующих пользователей останется доступен.</p><?php submit_button(); ?></form>
  <h2>Наставники</h2><p>Всего найдено: <?php echo (int) $query->get_total(); ?></p>
  <form method="get"><input type="hidden" name="page" value="wp-ntk"><label for="wp-ntk-search">Поиск по имени, логину или email</label> <input id="wp-ntk-search" name="s" value="<?php echo esc_attr( $search ); ?>"> <button class="button">Найти</button></form>
  <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_ntk_export' ), 'wp_ntk_export' ) ); ?>">Выгрузить всех наставников в CSV</a></p>
  <table class="widefat striped"><thead><tr><th>ФИО</th><th>Email аккаунта</th><th>Организация</th><th>Действие</th></tr></thead><tbody>
  <?php foreach ( $query->get_results() as $user ) : $values = WP_NTK_Fields::get_values( $user->ID ); ?><tr><td><?php echo esc_html( $values['fio'] ); ?></td><td><?php echo esc_html( $user->user_email ); ?></td><td><?php echo esc_html( $values['organization'] ); ?></td><td><?php if ( current_user_can( 'edit_user', $user->ID ) ) : ?><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">Изменить</a><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if ( ! $query->get_results() ) : ?><tr><td colspan="4">Наставников не найдено.</td></tr><?php endif; ?></tbody></table>
  <?php $pages = (int) ceil( $query->get_total() / 20 ); if ( $pages > 1 ) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>'; } ?>
  </div><?php
 }
}
