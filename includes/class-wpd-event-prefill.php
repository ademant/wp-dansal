<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layered merge for event overlay meta.
 *
 * Every "create a draft with pre-filled fields" surface (org-wide defaults on
 * a fresh auto-draft, templates, series add-date, clone) computes the
 * effective meta the same way: left-to-right, later sources override earlier
 * ones only for keys they explicitly set to a non-empty value.
 *
 * Treating empty/null/[] as "not set" is what lets a template selectively
 * override just a few fields on top of the org-wide defaults without every
 * template needing to enumerate the full field set.
 */
class WPD_Event_Prefill {

	/**
	 * @param array ...$sources Each source is meta_key => value.
	 * @return array Merged meta_key => value.
	 */
	public static function resolve( array ...$sources ) {
		$out = array();
		foreach ( $sources as $src ) {
			foreach ( $src as $key => $value ) {
				if ( '' === $value || null === $value || array() === $value ) {
					continue;
				}
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
