import { site } from './site.mjs';

export default function globalTeardown() {
	site( 'teardown' );
}
