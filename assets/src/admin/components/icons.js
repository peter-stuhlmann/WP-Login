/**
 * Inline icons (Lucide, ISC licence). Decorative: hidden from assistive technology.
 *
 * @param {Object} props
 * @param {number} [props.size]        Width and height in px.
 * @param {number} [props.strokeWidth] Line width in viewBox units.
 * @param {*}      props.children      SVG shapes.
 */
const Svg = ( { size = 20, strokeWidth = 2, children } ) => (
	<svg
		width={ size }
		height={ size }
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth={ strokeWidth }
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
		focusable="false"
	>
		{ children }
	</svg>
);

export const ExternalIcon = ( props ) => (
	<Svg { ...props }>
		<path d="M15 3h6v6" />
		<path d="M10 14 21 3" />
		<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
	</Svg>
);

export const CopyIcon = ( props ) => (
	<Svg { ...props }>
		<rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
		<path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
	</Svg>
);

export const CheckIcon = ( props ) => (
	<Svg { ...props }>
		<path d="M20 6 9 17l-5-5" />
	</Svg>
);

export const ChevronRightIcon = ( props ) => (
	<Svg { ...props }>
		<path d="m9 18 6-6-6-6" />
	</Svg>
);

export const PaletteIcon = ( props ) => (
	<Svg { ...props }>
		<circle cx="13.5" cy="6.5" r=".5" fill="currentColor" />
		<circle cx="17.5" cy="10.5" r=".5" fill="currentColor" />
		<circle cx="8.5" cy="7.5" r=".5" fill="currentColor" />
		<circle cx="6.5" cy="12.5" r=".5" fill="currentColor" />
		<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.12a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.55-2.5 5.55-5.55C21.97 6.01 17.46 2 12 2Z" />
	</Svg>
);

export const GripIcon = ( props ) => (
	<Svg { ...props }>
		<circle cx="9" cy="12" r="1" />
		<circle cx="9" cy="5" r="1" />
		<circle cx="9" cy="19" r="1" />
		<circle cx="15" cy="12" r="1" />
		<circle cx="15" cy="5" r="1" />
		<circle cx="15" cy="19" r="1" />
	</Svg>
);

export const PlugIcon = ( props ) => (
	<Svg { ...props }>
		<path d="M12 22v-5" />
		<path d="M9 8V2" />
		<path d="M15 8V2" />
		<path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z" />
	</Svg>
);

export const FingerprintIcon = ( props ) => (
	<Svg { ...props }>
		<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4" />
		<path d="M14 13.12c0 2.38 0 6.38-1 8.88" />
		<path d="M17.29 21.02c.12-.6.43-2.3.5-3.02" />
		<path d="M2 12a10 10 0 0 1 18-6" />
		<path d="M2 16h.01" />
		<path d="M21.8 16c.2-2 .131-5.354 0-6" />
		<path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2" />
		<path d="M8.65 22c.21-.66.45-1.32.57-2" />
		<path d="M9 6.8a6 6 0 0 1 9 5.2v2" />
	</Svg>
);

export const AlertIcon = ( props ) => (
	<Svg { ...props }>
		<circle cx="12" cy="12" r="10" />
		<line x1="12" x2="12" y1="8" y2="12" />
		<line x1="12" x2="12.01" y1="16" y2="16" />
	</Svg>
);

export const InfoIcon = ( props ) => (
	<Svg { ...props }>
		<circle cx="12" cy="12" r="10" />
		<path d="M12 16v-4" />
		<path d="M12 8h.01" />
	</Svg>
);

export const LockIcon = ( props ) => (
	<Svg { ...props }>
		<rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
		<path d="M7 11V7a5 5 0 0 1 10 0v4" />
	</Svg>
);

export const SparklesIcon = ( props ) => (
	<Svg { ...props }>
		<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
		<path d="M20 3v4" />
		<path d="M22 5h-4" />
	</Svg>
);

export const KeyIcon = ( props ) => (
	<Svg { ...props }>
		<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z" />
		<circle cx="16.5" cy="7.5" r=".5" fill="currentColor" />
	</Svg>
);

/** Provider logos in brand colours (same artwork as the login screen, src/Frontend/Icons.php). */
const BRANDS = {
	google: (
		<>
			<path
				fill="#4285F4"
				d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.46a5.52 5.52 0 0 1-2.4 3.62v3h3.88c2.27-2.09 3.58-5.17 3.58-8.81z"
			/>
			<path
				fill="#34A853"
				d="M12 24c3.24 0 5.96-1.07 7.94-2.9l-3.88-3.01c-1.07.72-2.45 1.15-4.06 1.15-3.13 0-5.78-2.11-6.72-4.95H1.27v3.11A12 12 0 0 0 12 24z"
			/>
			<path
				fill="#FBBC05"
				d="M5.28 14.29A7.2 7.2 0 0 1 4.9 12c0-.8.14-1.57.38-2.29V6.6H1.27A12 12 0 0 0 0 12c0 1.94.46 3.77 1.27 5.4l4.01-3.11z"
			/>
			<path
				fill="#EA4335"
				d="M12 4.75c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.27 6.6l4.01 3.11C6.22 6.86 8.87 4.75 12 4.75z"
			/>
		</>
	),
	linkedin: (
		<path
			fill="#0A66C2"
			d="M20.45 20.45h-3.56v-5.57c0-1.33-.03-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13zM7.12 20.45H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"
		/>
	),
	discord: (
		<path
			fill="#5865F2"
			d="M20.32 4.37a19.8 19.8 0 0 0-4.89-1.52.07.07 0 0 0-.08.04c-.21.38-.44.87-.61 1.25a18.27 18.27 0 0 0-5.49 0 12.64 12.64 0 0 0-.62-1.25.08.08 0 0 0-.08-.04 19.74 19.74 0 0 0-4.88 1.52.07.07 0 0 0-.03.03C.53 9.05-.32 13.58.1 18.06a.08.08 0 0 0 .03.06 19.9 19.9 0 0 0 5.99 3.03.08.08 0 0 0 .09-.03c.46-.63.87-1.3 1.22-1.99a.08.08 0 0 0-.04-.11 13.1 13.1 0 0 1-1.87-.89.08.08 0 0 1-.01-.13l.37-.29a.07.07 0 0 1 .08-.01c3.93 1.79 8.18 1.79 12.06 0a.07.07 0 0 1 .08.01l.37.29a.08.08 0 0 1-.01.13c-.6.35-1.22.64-1.87.89a.08.08 0 0 0-.04.11c.36.7.77 1.36 1.22 1.99a.08.08 0 0 0 .09.03 19.84 19.84 0 0 0 6-3.03.08.08 0 0 0 .03-.06c.5-5.18-.84-9.67-3.55-13.66a.06.06 0 0 0-.03-.03zM8.02 15.33c-1.18 0-2.16-1.09-2.16-2.42s.96-2.42 2.16-2.42c1.21 0 2.18 1.1 2.16 2.42 0 1.33-.96 2.42-2.16 2.42zm7.97 0c-1.18 0-2.16-1.09-2.16-2.42s.96-2.42 2.16-2.42c1.21 0 2.18 1.1 2.16 2.42 0 1.33-.95 2.42-2.16 2.42z"
		/>
	),
};

/**
 * @param {Object} props
 * @param {string} props.provider Provider id.
 * @param {number} [props.size]
 */
export const BrandIcon = ( { provider, size = 20 } ) =>
	BRANDS[ provider ] ? (
		<svg
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			aria-hidden="true"
			focusable="false"
		>
			{ BRANDS[ provider ] }
		</svg>
	) : (
		<KeyIcon size={ size } />
	);
