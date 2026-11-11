<?php
/** Формирование документов для экспорта проекта. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WP_NTK_Export_Documents {
 /** Все ячейки записываются как текст, поэтому ввод не становится формулой Excel. */
 private static function xml( $value ) {
  $value = wp_check_invalid_utf8( (string) $value );
  $value = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
  return htmlspecialchars( is_string( $value ) ? $value : '', ENT_QUOTES | ENT_XML1, 'UTF-8' );
 }
 public static function xlsx( $path, $rows, $sheet_name ) {
  if ( ! class_exists( 'ZipArchive' ) ) { throw new RuntimeException( 'Для XLSX требуется расширение PHP ZipArchive.' ); }
  $zip = new ZipArchive(); if ( true !== $zip->open( $path, ZipArchive::OVERWRITE ) ) { throw new RuntimeException( 'Не удалось создать XLSX.' ); }
  $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols><col min="1" max="1" width="35" customWidth="1"/><col min="2" max="2" width="80" customWidth="1"/></cols><sheetData>';
  $i = 0; foreach ( $rows as $row ) { ++$i; $sheet .= '<row r="' . $i . '"><c r="A' . $i . '" t="inlineStr" s="1"><is><t xml:space="preserve">' . self::xml( $row[0] ) . '</t></is></c><c r="B' . $i . '" t="inlineStr" s="2"><is><t xml:space="preserve">' . self::xml( $row[1] ) . '</t></is></c></row>'; }
  $sheet .= '</sheetData></worksheet>';
  $files = array(
   '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
   '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
   'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::xml( $sheet_name ) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
   'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
   'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
   'xl/worksheets/sheet1.xml' => $sheet,
  );
  $ok = true; foreach ( $files as $name => $data ) { if ( ! $zip->addFromString( $name, $data ) ) { $ok = false; break; } }
  if ( ! $zip->close() || ! $ok ) { throw new RuntimeException( 'Не удалось записать XLSX.' ); }
 }
 /** Ограничиваем размер фрагмента для быстрого переноса длинных текстов. */
 private static function pdf_text( $pdf, $text, $height ) {
  $text = wp_check_invalid_utf8( (string) $text );
  foreach ( explode( "\n", str_replace( "\r", '', $text ) ) as $paragraph ) {
   if ( '' === $paragraph ) { $pdf->Ln( $height ); continue; }
   preg_match_all( '/./us', $paragraph, $match ); $chars = $match[0]; $offset = 0; $count = count( $chars );
   while ( $offset < $count ) {
    $end = min( $offset + 180, $count );
    if ( $end < $count ) { for ( $i = $end - 1; $i > $offset + 80; --$i ) { if ( ' ' === $chars[$i] || "\t" === $chars[$i] ) { $end = $i + 1; break; } } }
    $pdf->MultiCell( 0, $height, implode( '', array_slice( $chars, $offset, $end - $offset ) ), 0, 'L' ); $offset = $end;
   }
  }
 }
 public static function pdf( $path, $rows ) {
  if ( ! function_exists( 'iconv' ) || ! function_exists( 'iconv_strlen' ) || ! function_exists( 'iconv_substr' ) || ! function_exists( 'gzcompress' ) ) { throw new RuntimeException( 'Для PDF необходимы расширения PHP iconv и zlib.' ); }
  require_once WP_NTK_DIR . 'lib/tfpdf/font/unifont/ttfonts.php';
  require_once WP_NTK_DIR . 'lib/tfpdf/tfpdf.php';
  $pdf = new WP_NTK_PDF_Library( 'P', 'mm', 'A4' ); $pdf->SetAutoPageBreak( true, 16 ); $pdf->SetMargins( 17, 16, 17 );
  $pdf->AddFont( 'ntk', '', 'DejaVuSansCondensed.ttf', true ); $pdf->AddPage(); $pdf->SetFont( 'ntk', '', 16 ); self::pdf_text( $pdf, 'Информация о проекте', 8 ); $pdf->Ln( 3 );
  foreach ( $rows as $row ) {
   $pdf->SetFont( 'ntk', '', 11 ); $pdf->SetTextColor( 45, 62, 82 ); self::pdf_text( $pdf, $row[0], 6 );
   $pdf->SetFont( 'ntk', '', 10 ); $pdf->SetTextColor( 20, 20, 20 ); self::pdf_text( $pdf, $row[1], 5 ); $pdf->Ln( 3 );
  }
  $data = $pdf->Output( 'S' ); if ( ! is_string( $data ) || 0 !== strpos( $data, '%PDF-' ) || strlen( $data ) !== file_put_contents( $path, $data, LOCK_EX ) ) { throw new RuntimeException( 'Не удалось записать PDF.' ); }
 }
}
