<?php
/** Закрытые вложения проектов. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WP_NTK_Project_Files {
 const OPTION = 'wp_ntk_project_file_sections';
 const META = '_wp_ntk_project_files';
 const TYPES = array( 'pdf' => 'PDF', 'docx' => 'DOCX', 'xlsx' => 'XLSX', 'pptx' => 'PPTX', 'jpg' => 'JPG', 'png' => 'PNG' );
 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_file_sections', array( $this, 'save_sections' ) );
  add_action( 'admin_post_wp_ntk_project_download', array( $this, 'download' ) );
  add_action( 'before_delete_post', array( $this, 'delete_project_files' ), 10, 2 );
 }
 public static function sections() { $v = get_option( self::OPTION, array() ); return is_array( $v ) ? $v : array(); }
 public static function files( $id ) { $v = $id ? get_post_meta( $id, self::META, true ) : array(); return is_array( $v ) ? $v : array(); }
 /** Каталог задаётся вне публичных директорий и заранее создаётся администратором сервера. */
 public static function root() {
  if ( ! defined( 'WP_NTK_PRIVATE_DIR' ) || ! is_string( WP_NTK_PRIVATE_DIR ) || '' === WP_NTK_PRIVATE_DIR ) { return false; }
  $root = realpath( WP_NTK_PRIVATE_DIR );
  $doc = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
  if ( ! $root || ! $doc || ! is_dir( $root ) || ! is_writable( $root ) ) { return false; }
  foreach ( array( $doc, realpath( ABSPATH ), realpath( WP_CONTENT_DIR ) ) as $public ) {
   if ( $public && ( $root === $public || 0 === strpos( $root . DIRECTORY_SEPARATOR, rtrim( $public, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) || 0 === strpos( $public . DIRECTORY_SEPARATOR, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) ) { return false; }
  }
  return $root;
 }
 public function menu() { add_submenu_page( 'wp-ntk', 'Файлы проектов', 'Файлы проектов', 'manage_options', 'wp-ntk-project-files', array( $this, 'page' ) ); }
 private function allowed() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); } }
 private function section_input( $row ) {
  if ( ! is_array( $row ) ) { wp_die( 'Некорректные настройки раздела.' ); }
  $name = isset( $row['name'] ) && is_string( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
  $max = isset( $row['max'] ) && is_string( $row['max'] ) ? $row['max'] : '';
  $types = isset( $row['types'] ) && is_array( $row['types'] ) ? array_values( array_intersect( array_keys( self::TYPES ), $row['types'] ) ) : array();
  if ( '' === $name || WP_NTK_Fields::length( $name ) > 150 || ! ctype_digit( $max ) || (int) $max < 1 || (int) $max > 50 || ! $types ) { wp_die( 'Укажите название, форматы и размер от 1 до 50 МБ для каждого раздела.' ); }
  return array( 'name' => $name, 'max' => (int) $max, 'types' => $types, 'required' => empty( $row['required'] ) ? 0 : 1, 'active' => empty( $row['active'] ) ? 0 : 1 );
 }
 public function save_sections() {
  $this->allowed(); check_admin_referer( 'wp_ntk_file_sections' );
  $stored = self::sections(); $posted = isset( $_POST['sections'] ) && is_array( $_POST['sections'] ) ? wp_unslash( $_POST['sections'] ) : array(); $out = array();
  foreach ( $stored as $key => $old ) { if ( ! preg_match( '/^f_[a-f0-9]{12}$/D', $key ) || ! isset( $posted[$key] ) ) { wp_die( 'Некорректные настройки разделов.' ); } $out[$key] = $this->section_input( $posted[$key] ); }
  $new = isset( $_POST['new_section'] ) && is_array( $_POST['new_section'] ) ? wp_unslash( $_POST['new_section'] ) : array();
  if ( ! empty( $new['name'] ) ) {
   if ( count( $out ) >= 12 ) { wp_die( 'Допускается не более 12 разделов.' ); }
   $section = $this->section_input( $new ); $key = 'f_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
   if ( isset( $out[$key] ) ) { wp_die( 'Не удалось создать раздел, попробуйте ещё раз.' ); }
   $out[$key] = $section;
  }
  update_option( self::OPTION, $out, false ); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-project-files&saved=1' ) ); exit;
 }
 private function row( $prefix, $section ) {
  echo '<p><label>Название <input class="regular-text" name="' . esc_attr( $prefix . '[name]' ) . '" maxlength="150" value="' . esc_attr( $section['name'] ) . '"></label> ';
  echo '<label>До <input type="number" name="' . esc_attr( $prefix . '[max]' ) . '" min="1" max="50" value="' . (int) $section['max'] . '" style="width:70px"> МБ</label> ';
  echo '<label><input type="checkbox" name="' . esc_attr( $prefix . '[required]' ) . '" value="1" ' . checked( ! empty( $section['required'] ), true, false ) . '> Обязательно</label> ';
  echo '<label><input type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="1" ' . checked( ! empty( $section['active'] ), true, false ) . '> Показывать раздел</label></p><p>Форматы: ';
  foreach ( self::TYPES as $key => $label ) { echo '<label style="margin-right:12px"><input type="checkbox" name="' . esc_attr( $prefix . '[types][]' ) . '" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $section['types'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
  echo '</p>';
 }
 public function page() {
  $this->allowed(); $sections = self::sections();
  echo '<div class="wrap"><h1>Файлы проектов</h1>';
  if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Разделы сохранены.</p></div>'; }
  if ( ! self::root() ) { echo '<div class="notice notice-error"><p>Закрытая папка не настроена или недоступна. Загрузка отключена. Администратор сервера должен создать папку вне корня сайта и прописать её абсолютный путь в wp-config.php: <code>define( \'WP_NTK_PRIVATE_DIR\', \'/путь/вне/webroot/ntk-private\' );</code>. Папка должна быть доступна PHP для записи; убедитесь, что она недоступна по HTTP и не опубликована другим веб-сервером.</p></div>'; }
  else { echo '<div class="notice notice-success"><p>Папка хранения найдена. Дополнительно проверьте, что она недоступна по HTTP.</p></div>'; }
  echo '<p>По одному файлу в каждом включённом разделе. Изменения и удаление файлов возможны только до окончания приёма. Выключенный раздел скрывается из форм; ранее загруженные файлы сохраняются. Файлы не входят в SMB-экспорт. Ограничения PHP и веб-сервера могут быть ниже указанных.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wp_ntk_file_sections">'; wp_nonce_field( 'wp_ntk_file_sections' );
  foreach ( $sections as $key => $section ) { echo '<hr><h2>' . esc_html( $section['name'] ) . '</h2>'; $this->row( 'sections[' . $key . ']', $section ); }
  echo '<hr><h2>Добавить раздел</h2><p>Оставьте название пустым, если не добавляете раздел.</p>'; $this->row( 'new_section', array( 'name' => '', 'max' => 10, 'required' => 0, 'active' => 1, 'types' => array( 'pdf' ) ) );
  submit_button( 'Сохранить разделы' ); echo '</form></div>';
 }
 private static function valid_file( $tmp, $ext ) {
  if ( ! is_uploaded_file( $tmp ) || ! function_exists( 'finfo_open' ) ) { return false; }
  $fi = finfo_open( FILEINFO_MIME_TYPE ); if ( ! $fi ) { return false; } $mime = finfo_file( $fi, $tmp ); finfo_close( $fi );
  if ( 'pdf' === $ext ) { $h = fopen( $tmp, 'rb' ); $magic = $h ? fread( $h, 5 ) : ''; if ( $h ) { fclose( $h ); } return 'application/pdf' === $mime && '%PDF-' === $magic; }
  if ( in_array( $ext, array( 'jpg','png' ), true ) ) { $img = @getimagesize( $tmp ); return is_array( $img ) && isset( $img['mime'] ) && $mime === $img['mime'] && $mime === ( 'jpg' === $ext ? 'image/jpeg' : 'image/png' ); }
  if ( ! in_array( $ext, array( 'docx','xlsx','pptx' ), true ) || ! class_exists( 'ZipArchive' ) || ! in_array( $mime, array( 'application/zip', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ), true ) ) { return false; }
  $zip = new ZipArchive(); if ( true !== $zip->open( $tmp, ZipArchive::RDONLY ) ) { return false; }
  $target = array( 'docx'=>'word/document.xml', 'xlsx'=>'xl/workbook.xml', 'pptx'=>'ppt/presentation.xml' );
  $ok = false !== $zip->locateName( '[Content_Types].xml' ) && false !== $zip->locateName( $target[$ext] ); $zip->close(); return $ok;
 }
 private static function remove_blob( $blob ) {
  $root = self::root(); if ( ! $root || ! is_string( $blob ) || ! preg_match( '/^[a-f0-9]{40}\.bin$/D', $blob ) ) { return; }
  $path = $root . DIRECTORY_SEPARATOR . $blob; if ( is_file( $path ) && ! is_link( $path ) && ! @unlink( $path ) ) { error_log( 'WP NTK: не удалось удалить файл проекта из закрытого хранилища: ' . $blob ); }
 }
 public static function rollback( $new ) { foreach ( $new as $blob ) { self::remove_blob( $blob ); } }
 /** Проверяет и переносит загруженные файлы; при ошибке удаляет все новые файлы. */
 public static function prepare( $id ) {
  $current = self::files( $id ); $files = $current; $new = array(); $retired = array(); $errors = array(); $sections = self::sections();
  foreach ( $sections as $key => $section ) {
   if ( empty( $section['active'] ) ) { continue; }
   $u = isset( $_FILES['wp_ntk_attachment'] ) && is_array( $_FILES['wp_ntk_attachment'] ) ? $_FILES['wp_ntk_attachment'] : array();
   $err = isset( $u['error'][$key] ) && is_int( $u['error'][$key] ) ? $u['error'][$key] : UPLOAD_ERR_NO_FILE;
   $remove = isset( $_POST['wp_ntk_remove_file'][$key] ) && '1' === $_POST['wp_ntk_remove_file'][$key];
   if ( UPLOAD_ERR_NO_FILE === $err ) { if ( $remove && isset( $files[$key] ) ) { if ( ! self::root() ) { $errors[] = 'Закрытая папка для файлов недоступна. Обратитесь к администратору.'; } else { $retired[] = $files[$key]['blob']; unset( $files[$key] ); } } }
   elseif ( UPLOAD_ERR_OK !== $err ) { $errors[] = 'Ошибка загрузки файла: ' . $section['name'] . '.'; }
   else {
    $root = self::root(); $name = isset( $u['name'][$key] ) && is_string( $u['name'][$key] ) ? sanitize_file_name( wp_unslash( $u['name'][$key] ) ) : '';
    $tmp = isset( $u['tmp_name'][$key] ) && is_string( $u['tmp_name'][$key] ) ? $u['tmp_name'][$key] : '';
    $size = $tmp && is_uploaded_file( $tmp ) ? filesize( $tmp ) : false;
    $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ); if ( 'jpeg' === $ext ) { $ext = 'jpg'; }
    if ( ! $root ) { $errors[] = 'Закрытая папка для файлов не настроена. Обратитесь к администратору.'; }
    elseif ( ! $size || $size > (int) $section['max'] * 1048576 || $size > wp_max_upload_size() ) { $errors[] = 'Файл раздела «' . $section['name'] . '» пуст или превышает разрешённый размер.'; }
    elseif ( ! in_array( $ext, $section['types'], true ) || ! self::valid_file( $tmp, $ext ) ) { $errors[] = 'Недопустимый формат файла в разделе «' . $section['name'] . '».'; }
    else {
     try { $blob = bin2hex( random_bytes( 20 ) ) . '.bin'; } catch ( Throwable $e ) { $blob = ''; }
     if ( ! $blob || file_exists( $root . DIRECTORY_SEPARATOR . $blob ) || ! move_uploaded_file( $tmp, $root . DIRECTORY_SEPARATOR . $blob ) ) { $errors[] = 'Не удалось сохранить файл раздела «' . $section['name'] . '».'; }
     elseif ( ! chmod( $root . DIRECTORY_SEPARATOR . $blob, 0600 ) ) { @unlink( $root . DIRECTORY_SEPARATOR . $blob ); $errors[] = 'Не удалось защитить файл раздела «' . $section['name'] . '».'; }
     else { $new[] = $blob; if ( isset( $files[$key]['blob'] ) ) { $retired[] = $files[$key]['blob']; } $files[$key] = array( 'blob' => $blob, 'name' => $name, 'section' => $section['name'], 'ext' => $ext, 'size' => $size ); }
    }
   }
   if ( ! empty( $section['required'] ) && ! isset( $files[$key] ) ) { $errors[] = 'Прикрепите файл в разделе «' . $section['name'] . '».'; }
  }
  if ( $errors ) { self::rollback( $new ); return array( 'errors' => $errors ); }
  return array( 'errors' => array(), 'files' => $files, 'new' => $new, 'retired' => $retired );
 }
 public static function commit( $id, $prepared ) {
  $expected = $prepared['files']; update_post_meta( $id, self::META, $expected );
  if ( self::files( $id ) !== $expected ) { self::rollback( $prepared['new'] ); return false; }
  foreach ( $prepared['retired'] as $blob ) { self::remove_blob( $blob ); } return true;
 }
 public static function url( $id, $key ) { return wp_nonce_url( add_query_arg( array( 'action' => 'wp_ntk_project_download', 'project' => $id, 'section' => $key ), admin_url( 'admin-post.php' ) ), 'wp_ntk_file_' . $id . '_' . $key ); }
 public function download() {
  $id = isset( $_GET['project'] ) && is_string( $_GET['project'] ) && ctype_digit( $_GET['project'] ) ? (int) $_GET['project'] : 0;
  $key = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? wp_unslash( $_GET['section'] ) : '';
  if ( ! $id || ! preg_match( '/^f_[a-f0-9]{12}$/D', $key ) ) { wp_die( 'Файл недоступен.', '', array( 'response' => 404 ) ); }
  check_admin_referer( 'wp_ntk_file_' . $id . '_' . $key );
  $p = get_post( $id ); $admin = current_user_can( 'manage_options' );
  if ( ! $p || WP_NTK_Projects::TYPE !== $p->post_type || ! in_array( $p->post_status, array( 'private','trash' ), true ) || ! is_user_logged_in() || ( ! $admin && ( 'private' !== $p->post_status || (int) $p->post_author !== get_current_user_id() ) ) ) { wp_die( 'Файл недоступен.', '', array( 'response' => 403 ) ); }
  $files = self::files( $id ); $f = isset( $files[$key] ) && is_array( $files[$key] ) ? $files[$key] : array(); $root = self::root();
  if ( ! $root || empty( $f['blob'] ) || ! preg_match( '/^[a-f0-9]{40}\.bin$/D', $f['blob'] ) ) { wp_die( 'Файл недоступен.', '', array( 'response' => 404 ) ); }
  $path = $root . DIRECTORY_SEPARATOR . $f['blob']; if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) { wp_die( 'Файл недоступен.', '', array( 'response' => 404 ) ); }
  $name = isset( $f['name'] ) ? sanitize_file_name( $f['name'] ) : 'file'; $name = str_replace( array( '"', "\r", "\n" ), '', $name );
  nocache_headers(); header( 'Content-Type: application/octet-stream' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Disposition: attachment; filename="download.' . ( isset( $f['ext'] ) && isset( self::TYPES[$f['ext']] ) ? $f['ext'] : 'bin' ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) ); header( 'Content-Length: ' . filesize( $path ) ); readfile( $path ); exit;
 }
 public function delete_project_files( $id, $post ) { if ( $post && WP_NTK_Projects::TYPE === $post->post_type ) { foreach ( self::files( $id ) as $f ) { if ( isset( $f['blob'] ) ) { self::remove_blob( $f['blob'] ); } } } }
}
