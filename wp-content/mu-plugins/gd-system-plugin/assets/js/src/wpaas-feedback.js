/** global wpaasFeedback */

import domReady from '@wordpress/dom-ready';
import apiFetch from '@wordpress/api-fetch';
import { render, useState, unmountComponentAtNode, useEffect } from '@wordpress/element';
import { close } from '@wordpress/icons';
import { Icon, RadioControl, Button } from '@wordpress/components';

import { ReactComponent as GoDaddyLogo } from './go-daddy-logo.svg';
import { logImpressionEvent, logInteractionEvent } from './instrumentation';

const surveyChoices = Array.from({ length: wpaasFeedback?.scoreChoices.max + 1 }, ( v, k ) => k + wpaasFeedback?.scoreChoices.min )
							.map( ( choice ) => ( { label: choice, value: choice } ) );

const surveyLabels = wpaasFeedback?.labels;

const EID_PREFIX = `wp.${ wp.editPost ? 'editor' : 'wpadmin' }`;

const startedAt = new Date().toISOString();
const DISMISS_KEY = 'wpaas-nux-feedback-dismiss';
const SESSION_VIEW_KEY = 'wpaas-nux-feedback-session';

//               hour  min  sec  ms
const daysInMs = 24 * 60 * 60 * 1000;

const browserDismiss = (days = 90) => {
	localStorage?.setItem( DISMISS_KEY, ( Date.now() + ( days * daysInMs ) ) );
}

const getDailySession = () => {
	const defaultSession = { count: 0, lastView: Date.now() };
	let session = localStorage?.getItem(SESSION_VIEW_KEY) || defaultSession;

	if (typeof session !== 'string') {
		return session;
	}

	try {
		session = JSON.parse(session);
	} catch {
		// Someone played with the localstorage
		logInteractionEvent({
			eid: `${ EID_PREFIX }.feedback/wpaas_nps/error/daily_session.modal`,
			type: 'custom',
			data: {
				message: 'Error Parsing Local Storage'
			}
		});
	}

	// Being careful with data that user can modify. Reset session if we are dealing with numbers.
	if (typeof session?.count !== 'number' || typeof session?.lastView !== 'number') {
		session = defaultSession;
	}

	return session;
}

const saveDailySession = (count = 0) => {
	localStorage?.setItem(SESSION_VIEW_KEY, JSON.stringify({ count, lastView: Date.now() }));
}

const saveDismissSurvey = ({ forceLocalDismiss = false } = {}) => {
	if (forceLocalDismiss) {
		browserDismiss();
		localStorage?.removeItem(SESSION_VIEW_KEY);
	}

	apiFetch( {
		url: wpaasFeedback.apiBase + '/dismiss',
		method: 'POST'
	} ).catch((error) => {
		// Log error to traffic here
		logInteractionEvent({
			eid: `${ EID_PREFIX }.feedback/wpaas_nps/error/dismiss.modal`,
			type: 'custom',
			data: {
				message: error?.message
			}
		});
		browserDismiss();
	} );
}

const Feedback = () => {
	const [ surveyScore, setSurveyScore ] = useState( null );
	const [ surveyComment, setSurveyComment ] = useState( '' );
	const [ contactChoice, setContactChoice ] = useState( null );
	const [ dismissSurvey, setDismissSurvey ] = useState( false );

	const [ showSuccess, setShowSuccess ] = useState( false );

	useEffect( () => {
		logImpressionEvent(`${ EID_PREFIX }.feedback/wpaas_nps.modal`);
	}, [] );

	useEffect( () => {
		if ( dismissSurvey ) {
			unmountComponentAtNode( wpaasFeedback.rootNode.getElementById( wpaasFeedback.mountPoint ) );
		}
	}, [ dismissSurvey ] );

	if ( ! surveyLabels ) {
		return null;
	}

	const handleDismissModal = () => {
		logInteractionEvent({
			eid: `${ EID_PREFIX }.feedback/wpaas_nps/modal/${ showSuccess ? 'success' : 'survey' }.close`
		});

		if ( ! showSuccess ) {
			saveDismissSurvey();
		}

		setDismissSurvey( true );
	}

	const handleSubmitModal = () => {
		setShowSuccess( true );

		logInteractionEvent({
			eid: `${ EID_PREFIX }.feedback/wpaas_nps/form/submit.button`
		});

		apiFetch( {
			url: wpaasFeedback.apiBase + '/score',
			method: 'POST',
			data: {
				'comment': surveyComment,
				'endedAt': new Date().toISOString(),
				'isWpAdmin' : wpaasFeedback.isWpAdmin,
				'score': surveyScore,
				startedAt,
				'wpUri': String( window.location.href ).replace( window.location.origin, '' ),
				'canContact': 'true' === contactChoice,
			}
		} ).catch((error) => {
			// Log error to traffic here
			logInteractionEvent({
				eid: `${ EID_PREFIX }.feedback/wpaas_nps/error/score.modal`,
				type: 'custom',
				data: {
					message: error?.message
				}
			});
			browserDismiss();
		} );
	}

	const surveyCommentMaxLength = wpaasFeedback?.commentLength;

	return (
		<div className="wpaas-feedback-modal__container">
			<div className="wpaas-feedback-modal__header">
				<Icon
					className="wpaas-feedback-modal__header__close"
					onClick={ handleDismissModal }
					icon={ close }
				/>
				{ !showSuccess && (
					<GoDaddyLogo />
				)}
			</div>
			<div className="wpaas-feedback-modal__content">
				{ showSuccess ? (
					<>
						<div className="wpaas-feedback__success">
							<GoDaddyLogo />
							<h4 className="wpaas-feedback__success__header">{ surveyLabels?.thank_you }</h4>
							<Button disabled={ !surveyScore ? true : false } onClick={ handleDismissModal } isPrimary>
								{ surveyLabels?.thank_you_button }
							</Button>
						</div>
					</>
				) : (
					<>
						<div className="wpaas-feedback__question-container">
							<label className="wpaas-feedback__question-label">{ surveyLabels?.survey_question }</label>
							<RadioControl
									selected={ surveyScore }
									options={ surveyChoices }
									onChange={ ( value ) => setSurveyScore( Number( value ) ) }
								/>
								<div className="wpaas-feedback__survey-question__labels">
									<p>{ surveyLabels?.not_likely }</p>
									<p>{ surveyLabels?.neutral }</p>
									<p>{ surveyLabels?.likely } </p>
								</div>
						</div>
						<div className="wpaas-feedback__question-container">
							<label className="wpaas-feedback__question-label">{ surveyLabels?.comment_text }</label>
							<div className="wpaas-feedback__textarea__container">
								<textarea
									value={ surveyComment }
									maxLength={ surveyCommentMaxLength }
									onChange={ e => setSurveyComment( e.target.value )}
								/>
								<p className={`wpaas-feedback__textarea__count${ surveyComment.length === surveyCommentMaxLength ? '-bold' : '' }`}>{ surveyComment.length } / { surveyCommentMaxLength }</p>
							</div>
						</div>
						<div className="wpaas-feedback__question-container contact">
							<label className="wpaas-feedback__question-label">{ surveyLabels?.can_we_contact }</label>
							<RadioControl
									selected={ contactChoice }
									options={ [ { label: 'Yes', value: 'true' }, { label: 'No', value: 'false' } ] }
									onChange={ ( value ) => setContactChoice( value ) }
								/>
						</div>
						<span>
							<span dangerouslySetInnerHTML={{ __html: surveyLabels?.privacy_disclaimer }} />
						</span>
						<div className="wpaas-feedback__submit-form">
							<Button
								disabled={ surveyScore === null || contactChoice === null }
								onClick={ handleSubmitModal }
								isPrimary
							>
								{ surveyLabels?.submit_feedback }
							</Button>
						</div>
					</>
				)}
			</div>
		</div>
	);
};

/**
 * customElements need ES5 classes but babel compiles them which errors out. We could use a polyfill or the below.
 * This is needed to circumvent babel crosscompiling.
 */
function BabelHTMLElement() {
	return Reflect.construct( HTMLElement, [], this.__proto__.constructor );
}
Object.setPrototypeOf( BabelHTMLElement, HTMLElement );
Object.setPrototypeOf( BabelHTMLElement.prototype, HTMLElement.prototype );

/**
 * See https://reactjs.org/docs/web-components.html#using-react-in-your-web-components
 */
class GoDaddyFeedback extends BabelHTMLElement {

	connectedCallback() {
		const mountPoint = document.createElement( 'div' );
		mountPoint.id = 'wpaas-feedback';

		function createStyle( url ) {
			const style = document.createElement( 'link' );
			style.setAttribute( 'rel', 'stylesheet' );
			style.setAttribute( 'href', url );
			style.setAttribute( 'media', 'all' );

			return style;
		}

		const shadowRoot = this.attachShadow( { mode: 'open' } );
		shadowRoot.appendChild( createStyle( wpaasFeedback.css ) );
		shadowRoot.appendChild( mountPoint );
		wpaasFeedback.rootNode = shadowRoot;
		wpaasFeedback.mountPoint = mountPoint.id;

		render(
			<Feedback />,
			mountPoint
		);
	}

}

function loadNpsComponent() {
	const dailySession = getDailySession();

	// If the last time the user saw the nps was more than 24 hours ago we reset counter.
	if (Date.now() > (dailySession.lastView + (1 * daysInMs))) {
		dailySession.count = 0;
	}

	if (dailySession.count >= 3) {
		logInteractionEvent({
			eid: `${ EID_PREFIX }.feedback/wpaas_nps/view_count.modal`,
			type: 'custom',
			data: {
				count: dailySession.count
			}
		});
		saveDismissSurvey({ forceLocalDismiss: true });

		return;
	}

	saveDailySession(++dailySession.count);

	// customElements always need hyphen in the name.
	const customElementName = wpaasFeedback.containerId;

	customElements.define( customElementName, GoDaddyFeedback );

	const element = document.createElement( customElementName );

	// The PHP script actually prints the following tag in the dom <div id="wpaas-feedback-container">.
	// We replace with the custom element the div printed by PHP.
	document.getElementById( customElementName ).replaceWith( element );
}

domReady( () => {
	const userDismiss = localStorage?.getItem( DISMISS_KEY );

	/*
	 * Do an extra safety check in case an optimization plugin picked up this script and cached it.
	 */
	const location = new URL(window.location);

	/**
	 * Can't trust the global pagenow from PHP if the script is cached by third party plugin.
	 * Get the current pagenow from the url instead while removing the /wp-admin/ part.
	 */
	const pagenow = location.pathname.replace(/^\/wp-admin\/(.*)$/, '$1');

	/**
	 * This is to force the NPS to show up for debugging purposes.
	 */
	const debug = location.search.includes(wpaasFeedback.debugParam);

	if (debug) {
		loadNpsComponent();
		return;
	}

	if (
		!wpaasFeedback.excludedPages
		|| userDismiss && userDismiss > Date.now()
		|| wpaasFeedback.excludedPages.includes(pagenow)
	) {
		return;
	}

	apiFetch( {
		url: wpaasFeedback.apiBase + '/available',
		method: 'POST'
	} )
	.then(loadNpsComponent)
	.catch((error) => {
		// Log error to traffic here
		logInteractionEvent({
			eid: `${ EID_PREFIX }.feedback/wpaas_nps/error/available.modal`,
			type: 'custom',
			data: {
				message: error?.message
			}
		});
		browserDismiss(1);
	} );
} );
