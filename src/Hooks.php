<?php declare( strict_types=1 );
/**
 * Semantic Structured Discussions MediaWiki extension
 * Copyright (C) 2022  Wikibase Solutions
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

namespace SemanticStructuredDiscussions;

use Flow\Api\ApiFlowBase;
use SemanticStructuredDiscussions\SemanticMediaWiki\PropertyInitializer;
use SMW\Maintenance\DataRebuilder;
use SMW\Options;
use SMW\PropertyRegistry;
use SMW\SemanticData;
use SMW\Services\ServicesFactory;
use SMW\Store;
use SMW\StoreFactory;

/**
 * @note This class should only contain static methods.
 */
final class Hooks {
	/**
	 * Disable the construction of this class by making the constructor private.
	 */
	private function __construct() {
	}

	/**
	 * Called after the extension is registered. Used to enable semantic links for the topic namespace.
	 */
	public static function onRegisterExtension(): void {
		// Enable semantic annotations for pages in the "Topic" namespace
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix
		global $smwgNamespacesWithSemanticLinks;
		$smwgNamespacesWithSemanticLinks[2600] = true;
	}

	/**
	 * Hook to add additional predefined properties.
	 *
	 * @param PropertyRegistry $propertyRegistry
	 * @return void
	 * @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/docs/examples/hook.property.initproperties.md
	 */
	public static function onInitProperties( PropertyRegistry $propertyRegistry ): void {
		$propertyInitializer = new PropertyInitializer( $propertyRegistry, Services::getAnnotatorStore() );
		$propertyInitializer->initializeProperties();
	}

	/**
	 * Hook to extend the SemanticData object before the update is completed.
	 *
	 * @param Store $store
	 * @param SemanticData $semanticData
	 * @return void
	 * @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/docs/technical/hooks/hook.store.beforedataupdatecomplete.md
	 */
	public static function onBeforeDataUpdateComplete( Store $store, SemanticData $semanticData ): void {
		$title = $semanticData->getSubject()->getTitle();

		if ( $title === null ) {
			return;
		}

		$topic = Services::getTopicRepository()->getByTitle( $title );

		if ( $topic === null ) {
			return;
		}

		Services::getDataAnnotator()->addAnnotations( $topic, $semanticData );
	}

	/**
	 * Hook to process information after an update has been completed.
	 *
	 * @param Store $store
	 * @param SemanticData $semanticData
	 * @return void
	 * @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/docs/technical/hooks/hook.store.afterdataupdatecomplete.md
	 */
	public static function onAfterDataUpdateComplete( Store $store, SemanticData $semanticData ): void {
		$title = $semanticData->getSubject()->getTitle();

		if ( $title === null ) {
			return;
		}

		$topics = Services::getTopicRepository()->getByOwner( $title );

		if ( empty( $topics ) ) {
			return;
		}

		foreach ( $topics as $topic ) {
			$title = $topic->getTopicTitle();
			if ( $title ) {
				self::rebuildForPage( $title );
			}
		}
	}

	/**
	 * Called after a Flow API module has been executed.
	 *
	 * This hook is used to (forcefully) update the semantic index after a write has been performed by Flow/SD.
	 *
	 * @param ApiFlowBase $module An instance of Flow\Api\ApiFlowBase
	 * @return void
	 * @link https://www.mediawiki.org/wiki/Extension:StructuredDiscussions/Hooks/APIFlowAfterExecute
	 */
	public static function onAPIFlowAfterExecute( ApiFlowBase $module ): void {
		if ( !$module->isWriteMode() ) {
			return;
		}

		$page = $module->getRequest()->getVal( 'page' );

		if ( $page === null ) {
			return;
		}

		self::rebuildForPage( $page );
	}

	/**
	 * This method is used to (forcefully) update the semantic index after a write has been performed by Flow/SD.
	 *
	 * @param string $pageName The page to rebuild
	 * @return void
	 */
	private static function rebuildForPage( string $pageName ): void {
		$store = StoreFactory::getStore();
		$store->setOption( Store::OPT_CREATE_UPDATE_JOB, false );

		$dataRebuilder = new DataRebuilder( $store, ServicesFactory::getInstance()->newTitleFactory() );
		$dataRebuilder->setOptions( new Options( [ 'page' => $pageName ] ) );
		$dataRebuilder->rebuild();
	}

	/**
	 * This method is used to reserve the usernames of the system user(s) used by this extension
	 *
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/UserGetReservedNames
	 */
	public static function onUserGetReservedNames( &$reservedUsernames ): void {
		$reservedUsernames []= 'SemanticStructuredDiscussions system user';
	}
}
