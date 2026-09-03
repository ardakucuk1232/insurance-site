<?php
/**
 * Plugin Name: Örnek Sigorta — Geri Arama Talepleri
 * Description: Sitedeki "Sizi biz arayalım" formunu veritabanına kaydeder. Talepler yönetim panelinde listelenir, durum (Bekliyor/Arandı) güncellenir ve Excel uyumlu CSV olarak indirilebilir.
 * Version: 1.0.0
 * Author: Arda
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Acente_Geri_Arama {

	const TABLO   = 'acente_geri_arama';
	const NONCE   = 'acente_ga_nonce';
	const KAPASITE = 5;
	const PENCERE  = 600;

	public function __construct() {
		register_activation_hook( __FILE__, [ $this, 'tablo_olustur' ] );
		add_action( 'wp_ajax_acente_geri_arama',        [ $this, 'talep_kaydet' ] );
		add_action( 'wp_ajax_nopriv_acente_geri_arama', [ $this, 'talep_kaydet' ] );
		add_action( 'admin_menu',                      [ $this, 'menu_ekle' ] );
		add_action( 'admin_post_acente_ga_durum',       [ $this, 'durum_degistir' ] );
		add_action( 'admin_post_acente_ga_sil',         [ $this, 'talep_sil' ] );
		add_action( 'admin_post_acente_ga_csv',         [ $this, 'csv_indir' ] );
		add_action( 'wp_enqueue_scripts',              [ $this, 'nonce_yayinla' ] );
	}

	public function tablo_olustur() {
		global $wpdb;
		$tablo   = $wpdb->prefix . self::TABLO;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$tablo} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ad_soyad VARCHAR(120) NOT NULL,
			telefon VARCHAR(20) NOT NULL,
			durum ENUM('bekliyor','arandi') NOT NULL DEFAULT 'bekliyor',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			olusturma DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY durum (durum),
			KEY olusturma (olusturma)
		) {$charset};" );
	}

	public function nonce_yayinla() {

		wp_localize_script( 'acente-tema', 'acenteGA', [
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
		] );
	}

	public function talep_kaydet() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! empty( $_POST['web_sitesi'] ) ) {
			wp_send_json_success();
		}

		$ip = $this->ip_al();

		$anahtar = 'acente_ga_' . md5( $ip );
		$sayac   = (int) get_transient( $anahtar );
		if ( $sayac >= self::KAPASITE ) {
			wp_send_json_error( [ 'mesaj' => 'Çok fazla deneme yapıldı. Lütfen bizi telefonla arayın.' ], 429 );
		}
		set_transient( $anahtar, $sayac + 1, self::PENCERE );

		$ad  = sanitize_text_field( wp_unslash( $_POST['ad_soyad'] ?? '' ) );
		$tel = preg_replace( '/\D/', '', (string) ( $_POST['telefon'] ?? '' ) );

		if ( strpos( $tel, '0090' ) === 0 ) $tel = substr( $tel, 4 );
		elseif ( strpos( $tel, '90' ) === 0 && strlen( $tel ) === 12 ) $tel = substr( $tel, 2 );
		if ( strpos( $tel, '0' ) === 0 ) $tel = substr( $tel, 1 );

		if ( mb_strlen( $ad ) < 3 || strlen( $tel ) !== 10 || $tel[0] !== '5' ) {
			wp_send_json_error( [ 'mesaj' => 'Ad soyad ve geçerli bir telefon numarası gerekli.' ], 400 );
		}

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . self::TABLO, [
			'ad_soyad'  => $ad,
			'telefon'   => '0' . $tel,
			'durum'     => 'bekliyor',
			'ip'        => $ip,
			'olusturma' => current_time( 'mysql' ),
		], [ '%s', '%s', '%s', '%s', '%s' ] );

		$alici = get_option( 'admin_email' );
		wp_mail(
			$alici,
			'Yeni geri arama talebi — ' . $ad,
			"Yeni bir geri arama talebi alındı.\n\nAd Soyad: {$ad}\nTelefon: 0{$tel}\n\nPanel: " . admin_url( 'admin.php?page=acente-geri-arama' )
		);

		wp_send_json_success( [ 'mesaj' => 'Talebiniz alındı. Çalışma saatleri içinde sizi arayacağız.' ] );
	}

	private function ip_al() {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public function menu_ekle() {
		global $wpdb;
		$bekleyen = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLO . " WHERE durum='bekliyor'"
		);
		$rozet = $bekleyen ? " <span class='awaiting-mod'>{$bekleyen}</span>" : '';
		add_menu_page(
			'Geri Arama Talepleri',
			'Geri Arama' . $rozet,
			'manage_options',
			'acente-geri-arama',
			[ $this, 'panel_ekrani' ],
			'dashicons-phone',
			26
		);
	}

	public function panel_ekrani() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		$tablo    = $wpdb->prefix . self::TABLO;
		$filtre   = ( $_GET['durum'] ?? '' ) === 'arandi' ? 'arandi' : ( ( $_GET['durum'] ?? '' ) === 'bekliyor' ? 'bekliyor' : '' );
		$sql      = "SELECT * FROM {$tablo}" . ( $filtre ? $wpdb->prepare( ' WHERE durum=%s', $filtre ) : '' ) . ' ORDER BY olusturma DESC LIMIT 500';
		$kayitlar = $wpdb->get_results( $sql );
		$csv_url  = wp_nonce_url( admin_url( 'admin-post.php?action=acente_ga_csv' ), 'acente_ga_csv' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Geri Arama Talepleri</h1>
			<a href="<?php echo esc_url( $csv_url ); ?>" class="page-title-action">Excel'e Aktar (CSV)</a>
			<hr class="wp-header-end">
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=acente-geri-arama' ) ); ?>" <?php if ( ! $filtre ) echo 'class="current"'; ?>>Tümü</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=acente-geri-arama&durum=bekliyor' ) ); ?>" <?php if ( 'bekliyor' === $filtre ) echo 'class="current"'; ?>>Bekliyor</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=acente-geri-arama&durum=arandi' ) ); ?>" <?php if ( 'arandi' === $filtre ) echo 'class="current"'; ?>>Arandı</a></li>
			</ul>
			<table class="wp-list-table widefat fixed striped" style="margin-top:12px">
				<thead><tr>
					<th style="width:60px">No</th><th>Ad Soyad</th><th>Telefon</th>
					<th>Tarih</th><th>Durum</th><th style="width:220px">İşlem</th>
				</tr></thead>
				<tbody>
				<?php if ( ! $kayitlar ) : ?>
					<tr><td colspan="6">Henüz talep yok.</td></tr>
				<?php else : foreach ( $kayitlar as $k ) :
					$durum_url = wp_nonce_url( admin_url( 'admin-post.php?action=acente_ga_durum&id=' . (int) $k->id ), 'acente_ga_durum_' . $k->id );
					$sil_url   = wp_nonce_url( admin_url( 'admin-post.php?action=acente_ga_sil&id=' . (int) $k->id ), 'acente_ga_sil_' . $k->id );
				?>
					<tr>
						<td><?php echo (int) $k->id; ?></td>
						<td><strong><?php echo esc_html( $k->ad_soyad ); ?></strong></td>
						<td><a href="tel:+9<?php echo esc_attr( $k->telefon ); ?>"><?php echo esc_html( $k->telefon ); ?></a></td>
						<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $k->olusturma ) ); ?></td>
						<td><?php echo 'arandi' === $k->durum
							? '<span style="color:#00a32a;font-weight:600">✓ Arandı</span>'
							: '<span style="color:#d63638;font-weight:600">● Bekliyor</span>'; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( $durum_url ); ?>">
								<?php echo 'arandi' === $k->durum ? 'Bekliyor yap' : 'Arandı işaretle'; ?>
							</a>
							<a class="button button-small" style="color:#d63638"
							   href="<?php echo esc_url( $sil_url ); ?>"
							   onclick="return confirm('Bu talep silinsin mi?');">Sil</a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<p style="color:#646970;margin-top:14px">
				Kişisel veri içerir: KVKK saklama süresi dolan kayıtları silmeyi unutmayın.
			</p>
		</div>
		<?php
	}

	public function durum_degistir() {
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'acente_ga_durum_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetki yok.' );
		global $wpdb;
		$tablo = $wpdb->prefix . self::TABLO;
		$mevcut = $wpdb->get_var( $wpdb->prepare( "SELECT durum FROM {$tablo} WHERE id=%d", $id ) );
		if ( $mevcut ) {
			$wpdb->update( $tablo, [ 'durum' => 'arandi' === $mevcut ? 'bekliyor' : 'arandi' ], [ 'id' => $id ] );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=acente-geri-arama' ) );
		exit;
	}

	public function talep_sil() {
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'acente_ga_sil_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetki yok.' );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . self::TABLO, [ 'id' => $id ], [ '%d' ] );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=acente-geri-arama' ) );
		exit;
	}

	public function csv_indir() {
		check_admin_referer( 'acente_ga_csv' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetki yok.' );
		global $wpdb;
		$kayitlar = $wpdb->get_results( "SELECT id, ad_soyad, telefon, durum, olusturma FROM {$wpdb->prefix}" . self::TABLO . ' ORDER BY olusturma DESC' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=geri-arama-talepleri-' . gmdate( 'Y-m-d' ) . '.csv' );
		$cikti = fopen( 'php://output', 'w' );
		fwrite( $cikti, "\xEF\xBB\xBF" );
		fputcsv( $cikti, [ 'No', 'Ad Soyad', 'Telefon', 'Durum', 'Tarih' ], ';' );
		foreach ( $kayitlar as $k ) {
			fputcsv( $cikti, [
				$k->id, $k->ad_soyad, $k->telefon,
				'arandi' === $k->durum ? 'Arandı' : 'Bekliyor',
				mysql2date( 'd.m.Y H:i', $k->olusturma ),
			], ';' );
		}
		fclose( $cikti );
		exit;
	}
}

new Acente_Geri_Arama();
