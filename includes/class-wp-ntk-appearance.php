<?php
/** Настройки оформления кабинета и экранов плагина. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WP_NTK_Appearance {
 const OPTION = 'wp_ntk_appearance';
 private static function defaults() {
  return array(
   'accent' => '#1d4ed8', 'accent_hover' => '#1e3fa8', 'text' => '#1a1f2b',
   'background' => '#ffffff', 'input_bg' => '#ffffff', 'input_text' => '#1a1f2b', 'border' => '#b9c0cc',
   'admin_accent' => '#2271b1', 'admin_text' => '#1d2327', 'admin_bg' => '#f0f0f1',
   'font' => 'inherit', 'font_size' => 16, 'width' => 720, 'radius' => 6, 'gap' => 20, 'padding' => 0,
   'extra_css' => '',
  );
 }
 private static function config() {
  $stored = get_option( self::OPTION, array() );
  return is_array( $stored ) ? array_merge( self::defaults(), $stored ) : self::defaults();
 }
 public function hooks() {
  add_action( 'admin_menu', array( $this, 'menu' ), 20 );
  add_action( 'admin_post_wp_ntk_appearance_save', array( $this, 'save' ) );
  add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
 }
 public function menu() { add_submenu_page( 'wp-ntk', 'Внешний вид', 'Внешний вид', 'manage_options', 'wp-ntk-appearance', array( $this, 'page' ) ); }
 private function input( $name ) { return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; }
 public function save() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); }
  check_admin_referer( 'wp_ntk_appearance_save' );
  if ( 'reset' === $this->input( 'operation' ) ) { delete_option( self::OPTION ); wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-appearance&reset=1' ) ); exit; }
  $c = self::defaults();
  foreach ( array( 'accent','accent_hover','text','background','input_bg','input_text','border','admin_accent','admin_text','admin_bg' ) as $key ) {
   $value = sanitize_hex_color( $this->input( $key ) );
   if ( ! $value ) { wp_die( 'Укажите корректные цвета в формате #RRGGBB.' ); }
   $c[$key] = $value;
  }
  $font = $this->input( 'font' ); if ( ! in_array( $font, array( 'inherit','system','arial','georgia' ), true ) ) { wp_die( 'Неизвестный шрифт.' ); } $c['font'] = $font;
  $ranges = array( 'font_size' => array( 14, 24 ), 'width' => array( 320, 1200 ), 'radius' => array( 0, 40 ), 'gap' => array( 0, 60 ), 'padding' => array( 0, 60 ) );
  foreach ( $ranges as $key => $range ) {
   $raw = $this->input( $key );
   if ( ! ctype_digit( $raw ) || (int) $raw < $range[0] || (int) $raw > $range[1] ) { wp_die( 'Числовые значения выходят за допустимые пределы. Вернитесь и исправьте настройки.' ); }
   $c[$key] = (int) $raw;
  }
  $css = $this->input( 'extra_css' );
  if ( strlen( $css ) > 20000 || false !== strpos( $css, '<' ) || false !== strpos( $css, '>' ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $css ) ) { wp_die( 'Дополнительный CSS слишком длинный или содержит недопустимые символы.' ); }
  $c['extra_css'] = $css;
  update_option( self::OPTION, $c, false );
  wp_safe_redirect( admin_url( 'admin.php?page=wp-ntk-appearance&saved=1' ) ); exit;
 }
 /** Стили добавляются только после первого сохранения, чтобы обновление не меняло существующий дизайн. */
 public static function front_css() {
  if ( ! is_array( get_option( self::OPTION, false ) ) ) { return ''; }
  $c = self::config();
  $fonts = array( 'inherit' => 'inherit', 'system' => 'system-ui, sans-serif', 'arial' => 'Arial, sans-serif', 'georgia' => 'Georgia, serif' );
  $font = isset( $fonts[$c['font']] ) ? $fonts[$c['font']] : 'inherit';
  $css = sprintf( '.wp-ntk{--wp-ntk-accent:%1$s;--wp-ntk-accent-hover:%2$s;--wp-ntk-text:%3$s;--wp-ntk-border:%4$s;--wp-ntk-radius:%5$dpx;max-width:%6$dpx;background:%7$s;color:%8$s;font-family:%9$s;font-size:%10$dpx;padding:%11$dpx;}',
   $c['accent'], $c['accent_hover'], $c['input_text'], $c['border'], $c['radius'], $c['width'], $c['background'], $c['text'], $font, $c['font_size'], $c['padding'] );
  $css .= sprintf( '.wp-ntk .wp-ntk-field{margin-bottom:%dpx}.wp-ntk .wp-ntk-input,.wp-ntk .wp-ntk-toggle{background:%s!important;color:%s!important}', $c['gap'], $c['input_bg'], $c['input_text'] );
  return $css . "\n" . $c['extra_css'];
 }
 public function admin_styles( $hook ) {
  if ( 'toplevel_page_wp-ntk' !== $hook && 0 !== strpos( $hook, 'wp-ntk_page_wp-ntk-' ) ) { return; }
  if ( ! is_array( get_option( self::OPTION, false ) ) ) { return; }
  $c = self::config();
  $fonts = array( 'inherit' => 'inherit', 'system' => 'system-ui, sans-serif', 'arial' => 'Arial, sans-serif', 'georgia' => 'Georgia, serif' );
  $font = isset( $fonts[$c['font']] ) ? $fonts[$c['font']] : 'inherit';
  $css = sprintf( 'body.wp-admin #wpbody-content > .wrap{background:%1$s;color:%2$s;font-family:%3$s;padding:%4$dpx;border-radius:%5$dpx;font-size:%7$dpx}body.wp-admin #wpbody-content > .wrap h1,body.wp-admin #wpbody-content > .wrap h2,body.wp-admin #wpbody-content > .wrap h3{color:%2$s}body.wp-admin #wpbody-content > .wrap .button-primary{background:%6$s;border-color:%6$s;color:#fff}body.wp-admin #wpbody-content > .wrap a:not(.button){color:%6$s}', $c['admin_bg'], $c['admin_text'], $font, $c['padding'], $c['radius'], $c['admin_accent'], $c['font_size'] );
  wp_register_style( 'wp-ntk-admin-appearance', false, array(), WP_NTK_VERSION ); wp_enqueue_style( 'wp-ntk-admin-appearance' ); wp_add_inline_style( 'wp-ntk-admin-appearance', $css . "\n" . $c['extra_css'] );
 }
 public function page() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) ); }
  $c = self::config();
  $colors = array( 'accent'=>'Кнопки и активная вкладка', 'accent_hover'=>'Кнопки при наведении', 'text'=>'Текст кабинета', 'background'=>'Фон кабинета', 'input_bg'=>'Фон полей', 'input_text'=>'Текст в полях', 'border'=>'Рамки полей', 'admin_accent'=>'Акцент панели плагина', 'admin_text'=>'Текст панели плагина', 'admin_bg'=>'Фон панели плагина' );
  $numbers = array( 'font_size'=>array('Размер шрифта кабинета и панели, px',14,24), 'width'=>array('Максимальная ширина кабинета, px',320,1200), 'radius'=>array('Скругление, px',0,40), 'gap'=>array('Отступ между полями, px',0,60), 'padding'=>array('Внутренний отступ кабинета и панели, px',0,60) );
  ?>
  <div class="wrap"><h1>Внешний вид WP NTK</h1>
  <?php if ( isset( $_GET['saved'] ) || isset( $_GET['reset'] ) ) : ?><div class="notice notice-success"><p>Настройки внешнего вида обновлены.</p></div><?php endif; ?>
  <p>Настройки применяются к кабинету и к страницам плагина в консоли. Тема сайта может влиять на итоговый вид. Выберите достаточно контрастные цвета.</p>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wp_ntk_appearance_save"><?php wp_nonce_field( 'wp_ntk_appearance_save' ); ?>
  <h2>Цвета</h2><table class="form-table"><tbody><?php foreach ( $colors as $key => $label ) : ?><tr><th><label for="ntk-style-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input type="color" id="ntk-style-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $c[$key] ); ?>"></td></tr><?php endforeach; ?></tbody></table>
  <h2>Типографика и размеры</h2><table class="form-table"><tbody><tr><th><label for="ntk-style-font">Шрифт</label></th><td><select id="ntk-style-font" name="font"><?php foreach ( array( 'inherit'=>'Как в теме / WordPress', 'system'=>'Системный без засечек', 'arial'=>'Arial', 'georgia'=>'Georgia' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $c['font'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
  <?php foreach ( $numbers as $key => $spec ) : ?><tr><th><label for="ntk-style-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $spec[0] ); ?></label></th><td><input type="number" id="ntk-style-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" min="<?php echo (int) $spec[1]; ?>" max="<?php echo (int) $spec[2]; ?>" required value="<?php echo (int) $c[$key]; ?>"> <span class="description"><?php echo (int) $spec[1] . '–' . (int) $spec[2]; ?></span></td></tr><?php endforeach; ?></tbody></table>
  <h2>Дополнительный CSS</h2><p>CSS добавляется на страницах с кабинетом и на страницах панели WP NTK. Правила могут изменить и окружающую страницу; используйте селекторы <code>.wp-ntk</code> для кабинета. Не вставляйте HTML и теги style. Доступно только администраторам.</p>
  <textarea name="extra_css" rows="10" class="large-text code" maxlength="20000"><?php echo esc_textarea( $c['extra_css'] ); ?></textarea>
  <p><button type="submit" class="button button-primary" name="operation" value="save">Сохранить оформление</button> <button type="submit" class="button" name="operation" value="reset" formnovalidate onclick="return confirm('Вернуть стандартное оформление?');">Сбросить настройки</button></p>
  </form></div><?php
 }
}
