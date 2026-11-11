<?php
/** Настройки полей анкеты. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WP_NTK_Field_Editor {
 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ) );
  add_action( 'admin_post_wp_ntk_fields', array( $this, 'save' ) );
 }
 public function menu() {
  add_submenu_page( 'wp-ntk', 'Поля регистрации', 'Поля регистрации', 'manage_options', 'wp-ntk-fields', array( $this, 'page' ) );
 }
 public function save() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.' ); }
  check_admin_referer( 'wp_ntk_fields' );
  $fields = WP_NTK_Fields::all();
  $posted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
  $settings = array();
  foreach ( $fields as $key => $field ) {
   $row = isset( $posted[ $key ] ) && is_array( $posted[ $key ] ) ? $posted[ $key ] : array();
   if ( ! isset( $row['label'] ) || ! is_string( $row['label'] ) ) { wp_die( 'Не удалось сохранить: отсутствуют настройки поля.' ); }
   $label = sanitize_text_field( $row['label'] );
   if ( '' === $label || WP_NTK_Fields::length( $label ) > 200 ) { wp_die( 'Название поля должно содержать от 1 до 200 символов.' ); }
   if ( isset( $row['delete'] ) && 0 === strpos( $key, 'custom_' ) ) { continue; }
   $entry = array( 'label' => $label, 'required' => empty( $row['required'] ) ? 0 : 1, 'order' => isset( $row['order'] ) ? max( 0, min( 9999, (int) $row['order'] ) ) : 0 );
   if ( 0 === strpos( $key, 'custom_' ) ) {
    $entry['type'] = $field['type'];
    $entry['options'] = $field['options'];
    if ( 'select' === $field['type'] ) { $entry['options'] = $this->options( isset( $row['options'] ) && is_string( $row['options'] ) ? $row['options'] : '' ); }
   }
   $settings[ $key ] = $entry;
  }
  $new = isset( $_POST['new_field'] ) && is_array( $_POST['new_field'] ) ? wp_unslash( $_POST['new_field'] ) : array();
  $label = isset( $new['label'] ) && is_string( $new['label'] ) ? sanitize_text_field( $new['label'] ) : '';
  if ( '' !== $label ) {
   if ( count( $settings ) >= 30 || WP_NTK_Fields::length( $label ) > 200 ) { wp_die( 'Слишком много полей или слишком длинное название.' ); }
   $type = isset( $new['type'] ) && is_string( $new['type'] ) ? sanitize_key( $new['type'] ) : '';
   if ( ! in_array( $type, array( 'text', 'textarea', 'select', 'checkbox' ), true ) ) { wp_die( 'Неизвестный тип поля.' ); }
   $opts = 'select' === $type ? $this->options( isset( $new['options'] ) && is_string( $new['options'] ) ? $new['options'] : '' ) : array();
   $key = 'custom_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
   $settings[ $key ] = array( 'label' => $label, 'type' => $type, 'required' => empty( $new['required'] ) ? 0 : 1, 'order' => isset( $new['order'] ) ? max( 0, min( 9999, (int) $new['order'] ) ) : 100, 'options' => $opts );
  }
  update_option( 'wp_ntk_field_settings', $settings );
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-fields&updated=1' ) ); exit;
 }
 private function options( $raw ) {
  $items = array();
  foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $item ) {
   $item = sanitize_text_field( $item );
   if ( '' !== $item ) {
    if ( WP_NTK_Fields::length( $item ) > 200 ) { wp_die( 'Вариант списка слишком длинный.' ); }
    $items[] = $item;
   }
  }
  if ( count( $items ) < 1 || count( $items ) > 50 || count( array_unique( $items ) ) !== count( $items ) ) { wp_die( 'Укажите от 1 до 50 различных вариантов списка, по одному на строку.' ); }
  return $items;
 }
 public function page() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.' ); }
  $fields = WP_NTK_Fields::all(); $page = WP_NTK_Setup::account_page_id();
  ?>
  <div class="wrap"><h1>Поля регистрации</h1>
  <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>Поля сохранены.</p></div><?php endif; ?>
  <?php if ( isset( $_GET['registration_saved'] ) ) : ?><div class="notice notice-success"><p>Настройки регистрации сохранены.</p></div><?php endif; ?>
  <?php $panel = new WP_NTK_Panel(); $panel->render_registration_settings(); ?>
  <hr><h2>Поля анкеты наставника</h2>
  <p>Поля анкеты отображаются при регистрации, в личном кабинете и профиле пользователя. Email для входа, пароль и согласие на обработку данных — отдельные обязательные поля.</p>
  <p>Меньший номер означает более раннее положение. Удаление дополнительного поля убирает его из форм, но не удаляет сохранённые данные пользователей. При изменении вариантов списка пользователям может потребоваться выбрать новый вариант.</p>
  <?php if ( $page ) : ?><p><a class="button" href="<?php echo esc_url( get_edit_post_link( $page ) ); ?>">Редактировать страницу и добавить блоки</a> <span>Добавьте блоки до или после шорткода <code>[wp_ntk_account]</code>. Они появятся и на других экранах кабинета.</span></p><?php endif; ?>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
  <input type="hidden" name="action" value="wp_ntk_fields"><?php wp_nonce_field( 'wp_ntk_fields' ); ?>
  <table class="widefat striped"><thead><tr><th>Название</th><th>Тип</th><th>Порядок</th><th>Обязательно</th><th>Варианты / удаление</th></tr></thead><tbody>
  <?php foreach ( $fields as $key => $field ) : $prefix = 'fields[' . $key . ']'; ?><tr>
  <td><input class="regular-text" maxlength="200" required name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( $field['label'] ); ?>"></td>
  <td><?php echo esc_html( array( 'text'=>'Строка', 'textarea'=>'Текст', 'email'=>'Email', 'select'=>'Список', 'checkbox'=>'Флажок' )[ $field['type'] ] ); ?></td>
  <td><input type="number" min="0" max="9999" name="<?php echo esc_attr( $prefix . '[order]' ); ?>" value="<?php echo (int) $field['order']; ?>" style="width:75px"></td>
  <td><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[required]' ); ?>" value="1" <?php checked( $field['required'] ); ?>> Да</label></td>
  <td><?php if ( 'select' === $field['type'] ) : ?><label>Варианты, по одному на строку<br><textarea name="<?php echo esc_attr( $prefix . '[options]' ); ?>" rows="3"><?php echo esc_textarea( implode( "\n", $field['options'] ) ); ?></textarea></label><br><?php endif; ?>
  <?php if ( 0 === strpos( $key, 'custom_' ) ) : ?><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[delete]' ); ?>" value="1"> Убрать поле из формы</label><?php else : ?>Базовое поле<?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <h2>Добавить поле</h2><p><label>Название <input type="text" name="new_field[label]" maxlength="200" class="regular-text"></label></p>
  <p><label>Тип <select name="new_field[type]"><option value="text">Строка</option><option value="textarea">Текстовая область</option><option value="select">Выпадающий список</option><option value="checkbox">Флажок</option></select></label> <label>Порядок <input type="number" name="new_field[order]" value="100" min="0" max="9999" style="width:75px"></label> <label><input type="checkbox" name="new_field[required]" value="1"> Обязательно</label></p>
  <p><label>Варианты для выпадающего списка (каждый с новой строки)<br><textarea name="new_field[options]" rows="3" class="regular-text"></textarea></label></p>
  <?php submit_button( 'Сохранить поля' ); ?></form></div>
  <?php
 }
}
