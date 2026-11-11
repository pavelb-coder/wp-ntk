<?php
/** Дополнительные поля проекта и их настройки. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WP_NTK_Project_Fields {
 const OPTION = 'wp_ntk_project_fields';
 const PREFIX = '_wp_ntk_project_field_';
 const LOCK = 'wp_ntk_project_fields_lock';
 const TYPES = array( 'text' => 'Короткий текст', 'textarea' => 'Длинный текст', 'select' => 'Список', 'checkbox' => 'Флажок', 'number' => 'Число', 'date' => 'Дата', 'url' => 'Ссылка' );
 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_project_field_save', array( $this, 'save_field' ) );
  add_action( 'admin_post_wp_ntk_project_field_delete', array( $this, 'delete_field' ) );
 }
 public function menu() { add_submenu_page( 'wp-ntk', 'Поля проектов', 'Поля проектов', 'manage_options', 'wp-ntk-project-fields', array( $this, 'page' ) ); }
 private function allowed() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); } }
 public static function all() { $v = get_option( self::OPTION, array() ); return is_array( $v ) ? $v : array(); }
 /** Единая блокировка записи ответов и изменения конфигурации предотвращает запись удалённого поля. */
 private static function lock() {
  $old = get_option( self::LOCK ); if ( is_array( $old ) && isset( $old['time'] ) && (int) $old['time'] < time() - 1800 ) { delete_option( self::LOCK ); }
  $token = wp_generate_uuid4(); return add_option( self::LOCK, array( 'time' => time(), 'token' => $token ), '', 'no' ) ? $token : false;
 }
 private static function unlock( $token ) { $old = get_option( self::LOCK ); if ( is_array( $old ) && isset( $old['token'] ) && $token === $old['token'] ) { delete_option( self::LOCK ); } }
 public static function revision( $fields ) { return hash( 'sha256', wp_json_encode( $fields ) ); }
 public static function values( $id, $fields = null ) { if ( null === $fields ) { $fields = self::all(); } $out = array(); foreach ( $fields as $key => $field ) { $v = $id ? get_post_meta( $id, self::PREFIX . $key, true ) : ''; $out[$key] = is_scalar( $v ) ? (string) $v : ''; } return $out; }
 private function input( $name ) { return isset( $_POST[$name] ) && is_string( $_POST[$name] ) ? wp_unslash( $_POST[$name] ) : ''; }
 public function save_field() {
  $this->allowed(); check_admin_referer( 'wp_ntk_project_field_save' );
  $all = self::all(); $original = $all; $key = $this->input( 'field_key' ); $label = sanitize_text_field( $this->input( 'label' ) );
  if ( '' === $label || WP_NTK_Fields::length( $label ) > 200 ) { wp_die( 'Введите название поля длиной до 200 символов.' ); }
  if ( '' === $key ) {
   if ( count( $all ) >= 30 ) { wp_die( 'Допускается не более 30 полей.' ); }
   $type = $this->input( 'type' ); if ( ! isset( self::TYPES[$type] ) ) { wp_die( 'Неизвестный тип поля.' ); }
   $key = 'p_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 ); if ( isset( $all[$key] ) ) { wp_die( 'Не удалось создать поле. Попробуйте снова.' ); }
  } else {
   if ( ! preg_match( '/^p_[a-f0-9]{20}$/D', $key ) || ! isset( $all[$key]['type'] ) || ! isset( self::TYPES[$all[$key]['type']] ) ) { wp_die( 'Поле не найдено.' ); }
   $type = $all[$key]['type'];
  }
  $options = array();
  if ( 'select' === $type ) {
   foreach ( preg_split( '/\r\n|\r|\n/', $this->input( 'options' ) ) as $item ) { $item = sanitize_text_field( $item ); if ( '' !== $item ) { if ( WP_NTK_Fields::length( $item ) > 200 ) { wp_die( 'Вариант списка слишком длинный.' ); } $options[] = $item; } }
   if ( ! $options || count( $options ) > 50 || count( $options ) !== count( array_unique( $options ) ) ) { wp_die( 'Укажите от 1 до 50 различных вариантов, по одному на строку.' ); }
  }
  $order = $this->input( 'order' ); $all[$key] = array( 'label' => $label, 'type' => $type, 'required' => empty( $_POST['required'] ) ? 0 : 1, 'order' => ctype_digit( $order ) ? min( 9999, (int) $order ) : 100, 'options' => $options );
  $lock = self::lock(); if ( ! $lock ) { wp_die( 'Другая операция с полями уже выполняется. Повторите попытку.' ); }
  try { $saved = self::all() === $original; if ( $saved ) { update_option( self::OPTION, $all, false ); $saved = self::all() === $all; } } finally { self::unlock( $lock ); }
  if ( ! $saved ) { wp_die( 'Настройки полей изменились или не сохранились. Обновите страницу и попробуйте снова.' ); }
  do_action( 'wp_ntk_project_changed' ); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-project-fields&saved=1' ) ); exit;
 }
 public function delete_field() {
  $this->allowed(); $key = $this->input( 'field_key' );
  if ( ! preg_match( '/^p_[a-f0-9]{20}$/D', $key ) ) { wp_die( 'Некорректное поле.' ); }
  check_admin_referer( 'wp_ntk_project_field_delete_' . $key ); $all = self::all(); if ( ! isset( $all[$key] ) ) { wp_die( 'Поле не найдено.' ); }
  $lock = self::lock(); if ( ! $lock ) { wp_die( 'Другая операция с полями уже выполняется. Повторите попытку.' ); }
  $error = '';
  try {
   if ( self::all() !== $all ) { $error = 'Настройки изменились. Обновите страницу и попробуйте снова.'; }
   else {
    // Удаляем все ответы, включая проекты в корзине; ключ уникален и не используется повторно.
    delete_post_meta_by_key( self::PREFIX . $key );
    global $wpdb; $left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::PREFIX . $key ) );
    if ( $left ) { $error = 'Не удалось удалить все ответы поля. Поле оставлено в настройках; проверьте резервную копию и повторите действие.'; }
    else { unset( $all[$key] ); update_option( self::OPTION, $all, false ); if ( self::all() !== $all ) { $error = 'Ответы удалены, но не удалось обновить настройки. Повторите удаление поля.'; } }
   }
  } finally { self::unlock( $lock ); }
  if ( $error ) { wp_die( esc_html( $error ) ); }
  do_action( 'wp_ntk_project_changed' ); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-project-fields&deleted=1' ) ); exit;
 }
 public function page() {
  $this->allowed(); $fields = self::ordered(); echo '<div class="wrap"><h1>Поля проектов</h1><p>Дополнительные поля отображаются после описания проекта. Настройки файловых разделов находятся отдельно в «Файлы проектов». Тип созданного поля изменить нельзя. При удалении поля все его ответы в проектах, включая корзину, будут удалены без возможности восстановления средствами плагина.</p>';
  if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Поле сохранено.</p></div>'; } if ( isset( $_GET['deleted'] ) ) { echo '<div class="notice notice-success"><p>Поле и его ответы удалены.</p></div>'; }
  foreach ( $fields as $key => $f ) {
   echo '<hr><h2>' . esc_html( $f['label'] ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_project_field_save"><input type="hidden" name="field_key" value="' . esc_attr( $key ) . '">'; wp_nonce_field( 'wp_ntk_project_field_save' );
   $this->row( $f ); submit_button( 'Сохранить поле' ); echo '</form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_project_field_delete"><input type="hidden" name="field_key" value="' . esc_attr( $key ) . '">'; wp_nonce_field( 'wp_ntk_project_field_delete_' . $key ); echo '<button class="button button-link-delete" type="submit" onclick="return confirm(&quot;Удалить поле и ВСЕ сохранённые ответы, включая проекты в корзине? Это нельзя отменить.&quot;)">Удалить поле и ответы</button></form>';
  }
  echo '<hr><h2>Добавить поле</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_project_field_save"><input type="hidden" name="field_key" value="">'; wp_nonce_field( 'wp_ntk_project_field_save' ); $this->row( array( 'label'=>'', 'type'=>'', 'required'=>0, 'order'=>100, 'options'=>array() ) ); submit_button( 'Добавить поле' ); echo '</form></div>';
 }
 private function row( $f ) {
  echo '<p><label>Название <input class="regular-text" required maxlength="200" name="label" value="' . esc_attr( $f['label'] ) . '"></label></p><p>Тип: ';
  if ( '' === $f['type'] ) { echo '<select name="type">'; foreach ( self::TYPES as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; } echo '</select>'; }
  else { echo esc_html( self::TYPES[$f['type']] ); }
  echo ' <label>Порядок <input type="number" min="0" max="9999" name="order" value="' . (int) $f['order'] . '" style="width:80px"></label> <label><input type="checkbox" name="required" value="1" ' . checked( ! empty( $f['required'] ), true, false ) . '> Обязательно</label></p>';
  if ( '' === $f['type'] || 'select' === $f['type'] ) { echo '<p><label>Варианты списка (по одному на строку; только для типа «Список»)<br><textarea name="options" rows="4" class="regular-text">' . esc_textarea( implode( "\n", $f['options'] ) ) . '</textarea></label></p>'; }
 }
 public static function ordered() { $fields = self::all(); uasort( $fields, function( $a, $b ) { return (int) $a['order'] - (int) $b['order']; } ); return $fields; }
 /** Проверяет весь ввод на сервере до изменения проекта и файлов. */
 public static function validate( $fields, $id, &$errors ) {
  $input = isset( $_POST['wp_ntk_project_fields'] ) && is_array( $_POST['wp_ntk_project_fields'] ) ? wp_unslash( $_POST['wp_ntk_project_fields'] ) : array(); $old = self::values( $id, $fields ); $out = array();
  foreach ( $fields as $key => $f ) {
   $raw = isset( $input[$key] ) && is_string( $input[$key] ) ? $input[$key] : ''; $type = $f['type']; $v = 'textarea' === $type ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ); if ( 'checkbox' === $type ) { $v = '1' === $raw ? '1' : '0'; }
   $out[$key] = $v; $bad = false;
   if ( ! empty( $f['required'] ) && ( '' === $v || ( 'checkbox' === $type && '1' !== $v ) ) ) { $errors[] = 'Заполните поле «' . $f['label'] . '».'; continue; }
   if ( WP_NTK_Fields::length( $v ) > ( 'textarea' === $type ? 5000 : 2000 ) ) { $bad = true; }
   elseif ( '' !== $v && 'select' === $type && ! in_array( $v, $f['options'], true ) && $v !== $old[$key] ) { $bad = true; }
   elseif ( '' !== $v && 'number' === $type && ! preg_match( '/^-?(?:\d+)(?:\.\d+)?$/D', $v ) ) { $bad = true; }
   elseif ( '' !== $v && 'date' === $type ) { $d = DateTimeImmutable::createFromFormat( '!Y-m-d', $v ); if ( ! $d || $d->format( 'Y-m-d' ) !== $v ) { $bad = true; } }
   elseif ( '' !== $v && 'url' === $type ) { $url = wp_parse_url( $v ); if ( ! filter_var( $v, FILTER_VALIDATE_URL ) || ! is_array( $url ) || empty( $url['host'] ) || empty( $url['scheme'] ) || ! in_array( strtolower( $url['scheme'] ), array( 'http', 'https' ), true ) ) { $bad = true; } }
   if ( $bad ) { $errors[] = 'Проверьте значение поля «' . $f['label'] . '».'; }
  }
  return $out;
 }
 public static function save_values( $id, $values, $expected_fields ) {
  if ( ! $values && ! $expected_fields ) { return true; }
  $lock = self::lock(); if ( ! $lock ) { return false; }
  try {
   if ( self::all() !== $expected_fields ) { return false; }
   foreach ( $values as $key => $value ) { if ( ! isset( $expected_fields[$key] ) ) { return false; } update_post_meta( $id, self::PREFIX . $key, wp_slash( $value ) ); if ( (string) get_post_meta( $id, self::PREFIX . $key, true ) !== $value ) { return false; } }
   return true;
  } finally { self::unlock( $lock ); }
 }
 public static function display_value( $field, $value ) { return 'checkbox' === $field['type'] ? ( '1' === $value ? 'Да' : 'Нет' ) : $value; }
 public static function render_values( $id ) { $fields = self::ordered(); $values = self::values( $id, $fields ); foreach ( $fields as $key => $field ) { echo '<p><strong>' . esc_html( $field['label'] ) . ':</strong><br>' . nl2br( esc_html( self::display_value( $field, $values[$key] ) ) ) . '</p>'; } }
 public static function render_form( $id, $submitted = null ) {
  $fields = self::ordered(); $values = is_array( $submitted ) ? $submitted : self::values( $id, $fields ); echo '<input type="hidden" name="wp_ntk_project_fields_revision" value="' . esc_attr( self::revision( self::all() ) ) . '">';
  foreach ( $fields as $key => $f ) { $value = isset( $values[$key] ) ? $values[$key] : ''; $name = 'wp_ntk_project_fields[' . $key . ']'; $html_id = 'ntk-project-' . $key; $required = ! empty( $f['required'] ) ? ' required' : ''; echo '<div class="wp-ntk-field"><label class="wp-ntk-label" for="' . esc_attr( $html_id ) . '">' . esc_html( $f['label'] ) . ( $required ? ' *' : '' ) . '</label>';
   if ( 'textarea' === $f['type'] ) { echo '<textarea class="wp-ntk-input" id="' . esc_attr( $html_id ) . '" name="' . esc_attr( $name ) . '" maxlength="5000" rows="4"' . $required . '>' . esc_textarea( $value ) . '</textarea>'; }
   elseif ( 'select' === $f['type'] ) { echo '<select class="wp-ntk-input" id="' . esc_attr( $html_id ) . '" name="' . esc_attr( $name ) . '"' . $required . '><option value="">Выберите вариант</option>'; if ( '' !== $value && ! in_array( $value, $f['options'], true ) ) { echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . ' (ранее выбранный вариант)</option>'; } foreach ( $f['options'] as $opt ) { echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>'; } echo '</select>'; }
   elseif ( 'checkbox' === $f['type'] ) { echo '<input type="checkbox" id="' . esc_attr( $html_id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $value, '1', false ) . $required . '>'; }
   else { $type = in_array( $f['type'], array( 'number', 'date', 'url' ), true ) ? $f['type'] : 'text'; echo '<input class="wp-ntk-input" type="' . esc_attr( $type ) . '" id="' . esc_attr( $html_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $type ? ' step="any"' : '' ) . ( in_array( $type, array( 'text', 'url' ), true ) ? ' maxlength="2000"' : '' ) . $required . '>'; }
   echo '</div>';
  }
 }
}
