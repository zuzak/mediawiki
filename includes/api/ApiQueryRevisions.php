<?php
/**
 *
 *
 * Created on Sep 7, 2006
 *
 * Copyright © 2006 Yuri Astrakhan "<Firstname><Lastname>@gmail.com"
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * A query action to enumerate revisions of a given page, or show top revisions
 * of multiple pages. Various pieces of information may be shown - flags,
 * comments, and the actual wiki markup of the rev. In the enumeration mode,
 * ranges of revisions may be requested and filtered.
 *
 * @ingroup API
 */
class ApiQueryRevisions extends ApiQueryBase {

	private $diffto, $difftotext, $expandTemplates, $generateXML, $section,
		$token, $parseContent, $contentFormat;

	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'rv' );
	}

	private $fld_ids = false, $fld_flags = false, $fld_timestamp = false,
		$fld_size = false, $fld_sha1 = false, $fld_comment = false,
		$fld_parsedcomment = false, $fld_user = false, $fld_userid = false,
		$fld_content = false, $fld_tags = false, $fld_contentmodel = false;

	private $tokenFunctions;

	/** @deprecated since 1.24 */
	protected function getTokenFunctions() {
		// tokenname => function
		// function prototype is func($pageid, $title, $rev)
		// should return token or false

		// Don't call the hooks twice
		if ( isset( $this->tokenFunctions ) ) {
			return $this->tokenFunctions;
		}

		// If we're in JSON callback mode, no tokens can be obtained
		if ( !is_null( $this->getMain()->getRequest()->getVal( 'callback' ) ) ) {
			return array();
		}

		$this->tokenFunctions = array(
			'rollback' => array( 'ApiQueryRevisions', 'getRollbackToken' )
		);
		wfRunHooks( 'APIQueryRevisionsTokens', array( &$this->tokenFunctions ) );

		return $this->tokenFunctions;
	}

	/**
	 * @deprecated since 1.24
	 * @param int $pageid
	 * @param Title $title
	 * @param Revision $rev
	 * @return bool|string
	 */
	public static function getRollbackToken( $pageid, $title, $rev ) {
		global $wgUser;
		if ( !$wgUser->isAllowed( 'rollback' ) ) {
			return false;
		}

		return $wgUser->getEditToken(
			array( $title->getPrefixedText(), $rev->getUserText() ) );
	}

	public function execute() {
		$params = $this->extractRequestParams( false );

		// If any of those parameters are used, work in 'enumeration' mode.
		// Enum mode can only be used when exactly one page is provided.
		// Enumerating revisions on multiple pages make it extremely
		// difficult to manage continuations and require additional SQL indexes
		$enumRevMode = ( !is_null( $params['user'] ) || !is_null( $params['excludeuser'] ) ||
			!is_null( $params['limit'] ) || !is_null( $params['startid'] ) ||
			!is_null( $params['endid'] ) || $params['dir'] === 'newer' ||
			!is_null( $params['start'] ) || !is_null( $params['end'] ) );

		$pageSet = $this->getPageSet();
		$pageCount = $pageSet->getGoodTitleCount();
		$revCount = $pageSet->getRevisionCount();

		// Optimization -- nothing to do
		if ( $revCount === 0 && $pageCount === 0 ) {
			return;
		}

		if ( $revCount > 0 && $enumRevMode ) {
			$this->dieUsage(
				'The revids= parameter may not be used with the list options ' .
					'(limit, startid, endid, dirNewer, start, end).',
				'revids'
			);
		}

		if ( $pageCount > 1 && $enumRevMode ) {
			$this->dieUsage(
				'titles, pageids or a generator was used to supply multiple pages, ' .
					'but the limit, startid, endid, dirNewer, user, excludeuser, start ' .
					'and end parameters may only be used on a single page.',
				'multpages'
			);
		}

		if ( !is_null( $params['difftotext'] ) ) {
			$this->difftotext = $params['difftotext'];
		} elseif ( !is_null( $params['diffto'] ) ) {
			if ( $params['diffto'] == 'cur' ) {
				$params['diffto'] = 0;
			}
			if ( ( !ctype_digit( $params['diffto'] ) || $params['diffto'] < 0 )
				&& $params['diffto'] != 'prev' && $params['diffto'] != 'next'
			) {
				$this->dieUsage(
					'rvdiffto must be set to a non-negative number, "prev", "next" or "cur"',
					'diffto'
				);
			}
			// Check whether the revision exists and is readable,
			// DifferenceEngine returns a rather ambiguous empty
			// string if that's not the case
			if ( $params['diffto'] != 0 ) {
				$difftoRev = Revision::newFromID( $params['diffto'] );
				if ( !$difftoRev ) {
					$this->dieUsageMsg( array( 'nosuchrevid', $params['diffto'] ) );
				}
				if ( !$difftoRev->userCan( Revision::DELETED_TEXT, $this->getUser() ) ) {
					$this->setWarning( "Couldn't diff to r{$difftoRev->getID()}: content is hidden" );
					$params['diffto'] = null;
				}
			}
			$this->diffto = $params['diffto'];
		}

		$db = $this->getDB();
		$this->addTables( 'page' );
		$this->addFields( Revision::selectFields() );
		$this->addWhere( 'page_id = rev_page' );

		$prop = array_flip( $params['prop'] );

		// Optional fields
		$this->fld_ids = isset( $prop['ids'] );
		// $this->addFieldsIf('rev_text_id', $this->fld_ids); // should this be exposed?
		$this->fld_flags = isset( $prop['flags'] );
		$this->fld_timestamp = isset( $prop['timestamp'] );
		$this->fld_comment = isset( $prop['comment'] );
		$this->fld_parsedcomment = isset( $prop['parsedcomment'] );
		$this->fld_size = isset( $prop['size'] );
		$this->fld_sha1 = isset( $prop['sha1'] );
		$this->fld_contentmodel = isset( $prop['contentmodel'] );
		$this->fld_userid = isset( $prop['userid'] );
		$this->fld_user = isset( $prop['user'] );
		$this->token = $params['token'];

		if ( !empty( $params['contentformat'] ) ) {
			$this->contentFormat = $params['contentformat'];
		}

		$userMax = ( $this->fld_content ? ApiBase::LIMIT_SML1 : ApiBase::LIMIT_BIG1 );
		$botMax = ( $this->fld_content ? ApiBase::LIMIT_SML2 : ApiBase::LIMIT_BIG2 );
		$limit = $params['limit'];
		if ( $limit == 'max' ) {
			$limit = $this->getMain()->canApiHighLimits() ? $botMax : $userMax;
			$this->getResult()->setParsedLimit( $this->getModuleName(), $limit );
		}

		if ( !is_null( $this->token ) || $pageCount > 0 ) {
			$this->addFields( Revision::selectPageFields() );
		}

		if ( isset( $prop['tags'] ) ) {
			$this->fld_tags = true;
			$this->addTables( 'tag_summary' );
			$this->addJoinConds(
				array( 'tag_summary' => array( 'LEFT JOIN', array( 'rev_id=ts_rev_id' ) ) )
			);
			$this->addFields( 'ts_tags' );
		}

		if ( !is_null( $params['tag'] ) ) {
			$this->addTables( 'change_tag' );
			$this->addJoinConds(
				array( 'change_tag' => array( 'INNER JOIN', array( 'rev_id=ct_rev_id' ) ) )
			);
			$this->addWhereFld( 'ct_tag', $params['tag'] );
		}

		if ( isset( $prop['content'] ) || !is_null( $this->diffto ) || !is_null( $this->difftotext ) ) {
			// For each page we will request, the user must have read rights for that page
			$user = $this->getUser();
			/** @var $title Title */
			foreach ( $pageSet->getGoodTitles() as $title ) {
				if ( !$title->userCan( 'read', $user ) ) {
					$this->dieUsage(
						'The current user is not allowed to read ' . $title->getPrefixedText(),
						'accessdenied' );
				}
			}

			$this->addTables( 'text' );
			$this->addWhere( 'rev_text_id=old_id' );
			$this->addFields( 'old_id' );
			$this->addFields( Revision::selectTextFields() );

			$this->fld_content = isset( $prop['content'] );

			$this->expandTemplates = $params['expandtemplates'];
			$this->generateXML = $params['generatexml'];
			$this->parseContent = $params['parse'];
			if ( $this->parseContent ) {
				// Must manually initialize unset limit
				if ( is_null( $limit ) ) {
					$limit = 1;
				}
				// We are only going to parse 1 revision per request
				$this->validateLimit( 'limit', $limit, 1, 1, 1 );
			}
			if ( isset( $params['section'] ) ) {
				$this->section = $params['section'];
			} else {
				$this->section = false;
			}
		}

		// add user name, if needed
		if ( $this->fld_user ) {
			$this->addTables( 'user' );
			$this->addJoinConds( array( 'user' => Revision::userJoinCond() ) );
			$this->addFields( Revision::selectUserFields() );
		}

		// Bug 24166 - API error when using rvprop=tags
		$this->addTables( 'revision' );

		if ( $enumRevMode ) {
			// This is mostly to prevent parameter errors (and optimize SQL?)
			if ( !is_null( $params['startid'] ) && !is_null( $params['start'] ) ) {
				$this->dieUsage( 'start and startid cannot be used together', 'badparams' );
			}

			if ( !is_null( $params['endid'] ) && !is_null( $params['end'] ) ) {
				$this->dieUsage( 'end and endid cannot be used together', 'badparams' );
			}

			if ( !is_null( $params['user'] ) && !is_null( $params['excludeuser'] ) ) {
				$this->dieUsage( 'user and excludeuser cannot be used together', 'badparams' );
			}

			// Continuing effectively uses startid. But we can't use rvstartid
			// directly, because there is no way to tell the client to ''not''
			// send rvstart if it sent it in the original query. So instead we
			// send the continuation startid as rvcontinue, and ignore both
			// rvstart and rvstartid when that is supplied.
			if ( !is_null( $params['continue'] ) ) {
				$params['startid'] = $params['continue'];
				$params['start'] = null;
			}

			// This code makes an assumption that sorting by rev_id and rev_timestamp produces
			// the same result. This way users may request revisions starting at a given time,
			// but to page through results use the rev_id returned after each page.
			// Switching to rev_id removes the potential problem of having more than
			// one row with the same timestamp for the same page.
			// The order needs to be the same as start parameter to avoid SQL filesort.
			if ( is_null( $params['startid'] ) && is_null( $params['endid'] ) ) {
				$this->addTimestampWhereRange( 'rev_timestamp', $params['dir'],
					$params['start'], $params['end'] );
			} else {
				$this->addWhereRange( 'rev_id', $params['dir'],
					$params['startid'], $params['endid'] );
				// One of start and end can be set
				// If neither is set, this does nothing
				$this->addTimestampWhereRange( 'rev_timestamp', $params['dir'],
					$params['start'], $params['end'], false );
			}

			// must manually initialize unset limit
			if ( is_null( $limit ) ) {
				$limit = 10;
			}
			$this->validateLimit( 'limit', $limit, 1, $userMax, $botMax );

			// There is only one ID, use it
			$ids = array_keys( $pageSet->getGoodTitles() );
			$this->addWhereFld( 'rev_page', reset( $ids ) );

			if ( !is_null( $params['user'] ) ) {
				$this->addWhereFld( 'rev_user_text', $params['user'] );
			} elseif ( !is_null( $params['excludeuser'] ) ) {
				$this->addWhere( 'rev_user_text != ' .
					$db->addQuotes( $params['excludeuser'] ) );
			}
			if ( !is_null( $params['user'] ) || !is_null( $params['excludeuser'] ) ) {
				// Paranoia: avoid brute force searches (bug 17342)
				if ( !$this->getUser()->isAllowed( 'deletedhistory' ) ) {
					$bitmask = Revision::DELETED_USER;
				} elseif ( !$this->getUser()->isAllowedAny( 'suppressrevision', 'viewsuppressed' ) ) {
					$bitmask = Revision::DELETED_USER | Revision::DELETED_RESTRICTED;
				} else {
					$bitmask = 0;
				}
				if ( $bitmask ) {
					$this->addWhere( $db->bitAnd( 'rev_deleted', $bitmask ) . " != $bitmask" );
				}
			}
		} elseif ( $revCount > 0 ) {
			$max = $this->getMain()->canApiHighLimits() ? $botMax : $userMax;
			$revs = $pageSet->getRevisionIDs();
			if ( self::truncateArray( $revs, $max ) ) {
				$this->setWarning( "Too many values supplied for parameter 'revids': the limit is $max" );
			}

			// Get all revision IDs
			$this->addWhereFld( 'rev_id', array_keys( $revs ) );

			if ( !is_null( $params['continue'] ) ) {
				$this->addWhere( 'rev_id >= ' . intval( $params['continue'] ) );
			}
			$this->addOption( 'ORDER BY', 'rev_id' );

			// assumption testing -- we should never get more then $revCount rows.
			$limit = $revCount;
		} elseif ( $pageCount > 0 ) {
			$max = $this->getMain()->canApiHighLimits() ? $botMax : $userMax;
			$titles = $pageSet->getGoodTitles();
			if ( self::truncateArray( $titles, $max ) ) {
				$this->setWarning( "Too many values supplied for parameter 'titles': the limit is $max" );
			}

			// When working in multi-page non-enumeration mode,
			// limit to the latest revision only
			$this->addWhere( 'page_id=rev_page' );
			$this->addWhere( 'page_latest=rev_id' );

			// Get all page IDs
			$this->addWhereFld( 'page_id', array_keys( $titles ) );
			// Every time someone relies on equality propagation, god kills a kitten :)
			$this->addWhereFld( 'rev_page', array_keys( $titles ) );

			if ( !is_null( $params['continue'] ) ) {
				$cont = explode( '|', $params['continue'] );
				$this->dieContinueUsageIf( count( $cont ) != 2 );
				$pageid = intval( $cont[0] );
				$revid = intval( $cont[1] );
				$this->addWhere(
					"rev_page > $pageid OR " .
					"(rev_page = $pageid AND " .
					"rev_id >= $revid)"
				);
			}
			$this->addOption( 'ORDER BY', array(
				'rev_page',
				'rev_id'
			) );

			// assumption testing -- we should never get more then $pageCount rows.
			$limit = $pageCount;
		} else {
			ApiBase::dieDebug( __METHOD__, 'param validation?' );
		}

		$this->addOption( 'LIMIT', $limit + 1 );

		$count = 0;
		$res = $this->select( __METHOD__ );

		foreach ( $res as $row ) {
			if ( ++$count > $limit ) {
				// We've reached the one extra which shows that there are
				// additional pages to be had. Stop here...
				if ( !$enumRevMode ) {
					ApiBase::dieDebug( __METHOD__, 'Got more rows then expected' ); // bug report
				}
				$this->setContinueEnumParameter( 'continue', intval( $row->rev_id ) );
				break;
			}

			$fit = $this->addPageSubItem( $row->rev_page, $this->extractRowInfo( $row ), 'rev' );
			if ( !$fit ) {
				if ( $enumRevMode ) {
					$this->setContinueEnumParameter( 'continue', intval( $row->rev_id ) );
				} elseif ( $revCount > 0 ) {
					$this->setContinueEnumParameter( 'continue', intval( $row->rev_id ) );
				} else {
					$this->setContinueEnumParameter( 'continue', intval( $row->rev_page ) .
						'|' . intval( $row->rev_id ) );
				}
				break;
			}
		}
	}

	private function extractRowInfo( $row ) {
		$revision = new Revision( $row );
		$title = $revision->getTitle();
		$user = $this->getUser();
		$vals = array();
		$anyHidden = false;

		if ( $this->fld_ids ) {
			$vals['revid'] = intval( $revision->getId() );
			// $vals['oldid'] = intval( $row->rev_text_id ); // todo: should this be exposed?
			if ( !is_null( $revision->getParentId() ) ) {
				$vals['parentid'] = intval( $revision->getParentId() );
			}
		}

		if ( $this->fld_flags && $revision->isMinor() ) {
			$vals['minor'] = '';
		}

		if ( $this->fld_user || $this->fld_userid ) {
			if ( $revision->isDeleted( Revision::DELETED_USER ) ) {
				$vals['userhidden'] = '';
				$anyHidden = true;
			}
			if ( $revision->userCan( Revision::DELETED_USER, $user ) ) {
				if ( $this->fld_user ) {
					$vals['user'] = $revision->getRawUserText();
				}
				$userid = $revision->getRawUser();
				if ( !$userid ) {
					$vals['anon'] = '';
				}

				if ( $this->fld_userid ) {
					$vals['userid'] = $userid;
				}
			}
		}

		if ( $this->fld_timestamp ) {
			$vals['timestamp'] = wfTimestamp( TS_ISO_8601, $revision->getTimestamp() );
		}

		if ( $this->fld_size ) {
			if ( !is_null( $revision->getSize() ) ) {
				$vals['size'] = intval( $revision->getSize() );
			} else {
				$vals['size'] = 0;
			}
		}

		if ( $this->fld_sha1 ) {
			if ( $revision->isDeleted( Revision::DELETED_TEXT ) ) {
				$vals['sha1hidden'] = '';
				$anyHidden = true;
			}
			if ( $revision->userCan( Revision::DELETED_TEXT, $user ) ) {
				if ( $revision->getSha1() != '' ) {
					$vals['sha1'] = wfBaseConvert( $revision->getSha1(), 36, 16, 40 );
				} else {
					$vals['sha1'] = '';
				}
			}
		}

		if ( $this->fld_contentmodel ) {
			$vals['contentmodel'] = $revision->getContentModel();
		}

		if ( $this->fld_comment || $this->fld_parsedcomment ) {
			if ( $revision->isDeleted( Revision::DELETED_COMMENT ) ) {
				$vals['commenthidden'] = '';
				$anyHidden = true;
			}
			if ( $revision->userCan( Revision::DELETED_COMMENT, $user ) ) {
				$comment = $revision->getRawComment();

				if ( $this->fld_comment ) {
					$vals['comment'] = $comment;
				}

				if ( $this->fld_parsedcomment ) {
					$vals['parsedcomment'] = Linker::formatComment( $comment, $title );
				}
			}
		}

		if ( $this->fld_tags ) {
			if ( $row->ts_tags ) {
				$tags = explode( ',', $row->ts_tags );
				$this->getResult()->setIndexedTagName( $tags, 'tag' );
				$vals['tags'] = $tags;
			} else {
				$vals['tags'] = array();
			}
		}

		if ( !is_null( $this->token ) ) {
			$tokenFunctions = $this->getTokenFunctions();
			foreach ( $this->token as $t ) {
				$val = call_user_func( $tokenFunctions[$t], $title->getArticleID(), $title, $revision );
				if ( $val === false ) {
					$this->setWarning( "Action '$t' is not allowed for the current user" );
				} else {
					$vals[$t . 'token'] = $val;
				}
			}
		}

		$content = null;
		global $wgParser;
		if ( $this->fld_content || !is_null( $this->diffto ) || !is_null( $this->difftotext ) ) {
			$content = $revision->getContent( Revision::FOR_THIS_USER, $this->getUser() );
			// Expand templates after getting section content because
			// template-added sections don't count and Parser::preprocess()
			// will have less input
			if ( $content && $this->section !== false ) {
				$content = $content->getSection( $this->section, false );
				if ( !$content ) {
					$this->dieUsage(
						"There is no section {$this->section} in r" . $revision->getId(),
						'nosuchsection'
					);
				}
			}
			if ( $revision->isDeleted( Revision::DELETED_TEXT ) ) {
				$vals['texthidden'] = '';
				$anyHidden = true;
			} elseif ( !$content ) {
				$vals['textmissing'] = '';
			}
		}
		if ( $this->fld_content && $content ) {
			$text = null;

			if ( $this->generateXML ) {
				if ( $content->getModel() === CONTENT_MODEL_WIKITEXT ) {
					$t = $content->getNativeData(); # note: don't set $text

					$wgParser->startExternalParse(
						$title,
						ParserOptions::newFromContext( $this->getContext() ),
						Parser::OT_PREPROCESS
					);
					$dom = $wgParser->preprocessToDom( $t );
					if ( is_callable( array( $dom, 'saveXML' ) ) ) {
						$xml = $dom->saveXML();
					} else {
						$xml = $dom->__toString();
					}
					$vals['parsetree'] = $xml;
				} else {
					$this->setWarning( "Conversion to XML is supported for wikitext only, " .
						$title->getPrefixedDBkey() .
						" uses content model " . $content->getModel() );
				}
			}

			if ( $this->expandTemplates && !$this->parseContent ) {
				#XXX: implement template expansion for all content types in ContentHandler?
				if ( $content->getModel() === CONTENT_MODEL_WIKITEXT ) {
					$text = $content->getNativeData();

					$text = $wgParser->preprocess(
						$text,
						$title,
						ParserOptions::newFromContext( $this->getContext() )
					);
				} else {
					$this->setWarning( "Template expansion is supported for wikitext only, " .
						$title->getPrefixedDBkey() .
						" uses content model " . $content->getModel() );

					$text = false;
				}
			}
			if ( $this->parseContent ) {
				$po = $content->getParserOutput(
					$title,
					$revision->getId(),
					ParserOptions::newFromContext( $this->getContext() )
				);
				$text = $po->getText();
			}

			if ( $text === null ) {
				$format = $this->contentFormat ? $this->contentFormat : $content->getDefaultFormat();
				$model = $content->getModel();

				if ( !$content->isSupportedFormat( $format ) ) {
					$name = $title->getPrefixedDBkey();

					$this->dieUsage( "The requested format {$this->contentFormat} is not supported " .
						"for content model $model used by $name", 'badformat' );
				}

				$text = $content->serialize( $format );

				// always include format and model.
				// Format is needed to deserialize, model is needed to interpret.
				$vals['contentformat'] = $format;
				$vals['contentmodel'] = $model;
			}

			if ( $text !== false ) {
				ApiResult::setContent( $vals, $text );
			}
		}

		if ( $content && ( !is_null( $this->diffto ) || !is_null( $this->difftotext ) ) ) {
			static $n = 0; // Number of uncached diffs we've had

			if ( $n < $this->getConfig()->get( 'APIMaxUncachedDiffs' ) ) {
				$vals['diff'] = array();
				$context = new DerivativeContext( $this->getContext() );
				$context->setTitle( $title );
				$handler = $revision->getContentHandler();

				if ( !is_null( $this->difftotext ) ) {
					$model = $title->getContentModel();

					if ( $this->contentFormat
						&& !ContentHandler::getForModelID( $model )->isSupportedFormat( $this->contentFormat )
					) {

						$name = $title->getPrefixedDBkey();

						$this->dieUsage( "The requested format {$this->contentFormat} is not supported for " .
							"content model $model used by $name", 'badformat' );
					}

					$difftocontent = ContentHandler::makeContent(
						$this->difftotext,
						$title,
						$model,
						$this->contentFormat
					);

					$engine = $handler->createDifferenceEngine( $context );
					$engine->setContent( $content, $difftocontent );
				} else {
					$engine = $handler->createDifferenceEngine( $context, $revision->getID(), $this->diffto );
					$vals['diff']['from'] = $engine->getOldid();
					$vals['diff']['to'] = $engine->getNewid();
				}
				$difftext = $engine->getDiffBody();
				ApiResult::setContent( $vals['diff'], $difftext );
				if ( !$engine->wasCacheHit() ) {
					$n++;
				}
			} else {
				$vals['diff']['notcached'] = '';
			}
		}

		if ( $anyHidden && $revision->isDeleted( Revision::DELETED_RESTRICTED ) ) {
			$vals['suppressed'] = '';
		}

		return $vals;
	}

	public function getCacheMode( $params ) {
		if ( isset( $params['token'] ) ) {
			return 'private';
		}
		if ( $this->userCanSeeRevDel() ) {
			return 'private';
		}
		if ( !is_null( $params['prop'] ) && in_array( 'parsedcomment', $params['prop'] ) ) {
			// formatComment() calls wfMessage() among other things
			return 'anon-public-user-private';
		}

		return 'public';
	}

	public function getAllowedParams() {
		return array(
			'prop' => array(
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'ids|timestamp|flags|comment|user',
				ApiBase::PARAM_TYPE => array(
					'ids',
					'flags',
					'timestamp',
					'user',
					'userid',
					'size',
					'sha1',
					'contentmodel',
					'comment',
					'parsedcomment',
					'content',
					'tags'
				)
			),
			'limit' => array(
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2,
				ApiBase::PARAM_HELP_MSG_INFO => array( array( 'singlepageonly' ) ),
			),
			'startid' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_HELP_MSG_INFO => array( array( 'singlepageonly' ) ),
			),
			'endid' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_HELP_MSG_INFO => array( array( 'singlepageonly' ) ),
			),
			'start' => array(
				ApiBase::PARAM_TYPE => 'timestamp',
				ApiBase::PARAM_HELP_MSG_INFO => array( array( 'singlepageonly' ) ),
			),
			'end' => array(
				ApiBase::PARAM_TYPE => 'timestamp',
				ApiBase::PARAM_HELP_MSG_INFO => array( array( 'singlepageonly' ) ),
			),
			'dir' => array(
				ApiBase::PARAM_DFLT => 'older',
				ApiBase::PARAM_TYPE => array(
					'newer',
					'older'
				),
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
				ApiBase::PARAM_HELP_MSG_INFO => array( array( 'singlepageonly' ) ),
			),
			'user' => array(
				ApiBase::PARAM_TYPE => 'user',
				ApiBase::PARAM_HELP_MSG_INFO => array( array( 'singlepageonly' ) ),
			),
			'excludeuser' => array(
				ApiBase::PARAM_TYPE => 'user',
				ApiBase::PARAM_HELP_MSG_INFO => array( array( 'singlepageonly' ) ),
			),
			'tag' => null,
			'expandtemplates' => false,
			'generatexml' => false,
			'parse' => false,
			'section' => null,
			'token' => array(
				ApiBase::PARAM_DEPRECATED => true,
				ApiBase::PARAM_TYPE => array_keys( $this->getTokenFunctions() ),
				ApiBase::PARAM_ISMULTI => true
			),
			'continue' => array(
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			),
			'diffto' => null,
			'difftotext' => null,
			'contentformat' => array(
				ApiBase::PARAM_TYPE => ContentHandler::getAllContentFormats(),
				ApiBase::PARAM_DFLT => null
			),
		);
	}

	protected function getExamplesMessages() {
		return array(
			'action=query&prop=revisions&titles=API|Main%20Page&' .
				'rvprop=timestamp|user|comment|content'
				=> 'apihelp-query+revisions-example-content',
			'action=query&prop=revisions&titles=Main%20Page&rvlimit=5&' .
				'rvprop=timestamp|user|comment'
				=> 'apihelp-query+revisions-example-last5',
			'action=query&prop=revisions&titles=Main%20Page&rvlimit=5&' .
				'rvprop=timestamp|user|comment&rvdir=newer'
				=> 'apihelp-query+revisions-example-first5',
			'action=query&prop=revisions&titles=Main%20Page&rvlimit=5&' .
				'rvprop=timestamp|user|comment&rvdir=newer&rvstart=2006-05-01T00:00:00Z'
				=> 'apihelp-query+revisions-example-first5-after',
			'action=query&prop=revisions&titles=Main%20Page&rvlimit=5&' .
				'rvprop=timestamp|user|comment&rvexcludeuser=127.0.0.1'
				=> 'apihelp-query+revisions-example-first5-not-localhost',
			'action=query&prop=revisions&titles=Main%20Page&rvlimit=5&' .
				'rvprop=timestamp|user|comment&rvuser=MediaWiki%20default'
				=> 'apihelp-query+revisions-example-first5-user',
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/API:Properties#revisions_.2F_rv';
	}
}
