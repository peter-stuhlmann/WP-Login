<?php
/**
 * Hidden security fields every Portier form carries.
 *
 * @var array{token: string} $d
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<input type="hidden" name="portier_token" value="<?php echo esc_attr($d['token']); ?>">
