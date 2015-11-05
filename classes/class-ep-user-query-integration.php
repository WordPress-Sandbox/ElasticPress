<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class EP_User_Query_Integration {

	/** @var EP_Object_Index */
	private $user_index;

	/**
	 * EP_User_Query_Integration constructor.
	 *
	 * @param EP_Object_Index $user_index
	 */
	public function __construct( $user_index = null ) {
		$this->user_index = $user_index ? $user_index : ep_get_object_type( 'user' );
	}

	public function setup() {
		/**
		 * By default EP will not integrate on admin or ajax requests. Since admin-ajax.php is
		 * technically an admin request, there is some weird logic here. If we are doing ajax
		 * and ep_ajax_user_query_integration is filtered true, then we skip the next admin check.
		 */
		$admin_integration = apply_filters( 'ep_admin_user_query_integration', false );

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			if ( ! apply_filters( 'ep_ajax_user_query_integration', false ) ) {
				return;
			} else {
				$admin_integration = true;
			}
		}

		if ( is_admin() && ! $admin_integration ) {
			return;
		}

		if ( $this->is_user_indexing_active() ) {
			return;
		}
		add_action( 'pre_get_users', array( $this, 'action_pre_get_users' ), 99999 );
	}

	/**
	 * @param WP_User_Query $wp_user_query
	 */
	public function action_pre_get_users( $wp_user_query ) {
		if ( $this->is_query_basic_enough_to_skip( $wp_user_query ) || $this->skip_integration( $wp_user_query ) ) {
			// The User query MUST hit the database, so if this query is so basic that it wouldn't even join any tables
			// then we should just skip it outright
			return;
		}
		$default_args = array(
			'blog_id'             => null,
			'role'                => '',
			'meta_key'            => '',
			'meta_value'          => '',
			'meta_compare'        => '',
			'include'             => array(),
			'exclude'             => array(),
			'search'              => '',
			'search_columns'      => array(),
			'orderby'             => 'login',
			'order'               => 'ASC',
			'offset'              => '',
			'number'              => '',
			'count_total'         => false,
			'fields'              => 'all',
			'who'                 => '',
			'has_published_posts' => null,
		);

		$qv    = $wp_user_query->query_vars;
		$scope = $qv['blog_id'];
		if ( -1 === $scope ) {
			$scope = 'all';
		}
		if ( 'all' === $scope && ! apply_filters( 'ep_user_global_search_active', false ) ) {
			$scope = 'current';
		}
		if ( ! in_array( $scope, array( 'all', 'current' ) ) ) {
			$scope = array_filter( wp_parse_id_list( $scope ) );
		}
		$results = ep_search( $this->format_args( $wp_user_query ), $scope ? $scope : 'current', 'user' );

		if ( $results['found_objects'] < 1 ) {
			$wp_user_query->query_vars = $default_args;
			add_action( 'pre_user_query', array( $this, 'kill_query' ), 99999 );

			return;
		}

		$new_qv                 = $default_args;
		$new_qv['include']      = wp_list_pluck( $results['objects'], 'user_id' );
		$new_qv['orderby']      = 'include';
		$new_qv['fields']       = $qv['fields'];
		$new_qv['number']       = $qv['number'];
		$new_qv['elasticpress'] = true;

		$wp_user_query->query_vars = $new_qv;
	}

	/**
	 * @param WP_User_Query $wp_user_query
	 *
	 * @return array
	 */
	public function format_args( $wp_user_query ) {
		$arguments    = $wp_user_query->query_vars;
		$ep_arguments = array();
		if ( empty( $arguments['number'] ) ) {
			$arguments['number'] = (int) apply_filters(
				'ep_wp_user_query_integration_default_size',
				1000,
				$wp_user_query
			);
		}
		// Can't have negative numbers for size
		$ep_arguments['size'] = max( 0, (int) $arguments['number'] );
		$ep_arguments['from'] = max( 0, empty( $arguments['offset'] ) ? 0 : (int) $arguments['offset'] );

		if ( ! empty( $arguments['search'] ) && trim( $arguments['search'] ) ) {
			if ( empty( $arguments['order'] ) ) {
				$arguments['order'] = 'desc';
			}
			if ( empty( $arguments['orderby'] ) ) {
				$arguments['orderby'] = 'relevance';
			}
		}

		if ( $sorts = $this->parse_sorting( $arguments ) ) {
			$ep_arguments['sort'] = $sorts;
		}

		$filter     = array(
			'and' => array(),
		);
		$use_filter = false;

		/**
		 * Tax queries
		 *
		 * Because why not?
		 */
		if ( ! empty( $arguments['tax_query'] ) ) {
			$tax_filter = array();

			foreach ( $arguments['tax_query'] as $single_tax_query ) {
				if ( ! empty( $single_tax_query['terms'] ) && ! empty( $single_tax_query['field'] ) && 'slug' === $single_tax_query['field'] ) {
					$terms = (array) $single_tax_query['terms'];

					// Set up our terms object
					$terms_obj = array(
						'terms.' . $single_tax_query['taxonomy'] . '.slug' => $terms,
					);

					// Use the AND operator if passed
					if ( ! empty( $single_tax_query['operator'] ) && 'AND' === $single_tax_query['operator'] ) {
						$terms_obj['execution'] = 'and';
					}

					// Add the tax query filter
					$tax_filter[]['terms'] = $terms_obj;
				}
			}

			if ( ! empty( $tax_filter ) ) {
				$filter['and'][]['bool']['must'] = $tax_filter;

				$use_filter = true;
			}
		}
		// End tax queries

		/**
		 * include ID list
		 */
		if ( ! empty( $arguments['include'] ) ) {
			$filter['and'][]['bool']['must'] = array(
				'terms' => array(
					'user_id' => wp_parse_id_list( $arguments['include'] )
				)
			);

			$use_filter = true;
		}
		// end include id list

		/**
		 * exclude ID list
		 */
		if ( ! empty( $arguments['exclude'] ) ) {
			$filter['and'][]['bool']['must_not'] = array(
				'terms' => array(
					'user_id' => wp_parse_id_list( $arguments['exclude'] )
				)
			);

			$use_filter = true;
		}
		// end exclude id list

		/**
		 * 'date_query' arg support.
		 *
		 */
		if ( ! empty( $arguments['date_query'] ) ) {
			$date_query  = new EP_WP_Date_Query( $arguments['date_query'], 'user_registered' );
			$date_filter = $date_query->get_es_filter();
			if ( array_key_exists( 'and', $date_filter ) ) {
				$filter['and'][] = $date_filter['and'];
				$use_filter      = true;
			}
		}
		// end date query section

		$meta_query = new WP_Meta_Query();
		$meta_query->parse_query_vars( $arguments );
		/**
		 * 'meta_query' arg support.
		 *
		 * Relation supports 'AND' and 'OR'. 'AND' is the default. For each individual query, the
		 * following 'compare' values are supported: =, !=, EXISTS, NOT EXISTS. '=' is the default.
		 * 'type' is NOT support at this time.
		 */
		if ( ! empty( $meta_query->queries ) ) {
			$meta_filter = array();

			$relation = 'must';
			if ( ! empty( $meta_query->queries['relation'] ) && 'or' === strtolower( $meta_query->queries['relation'] ) ) {
				$relation = 'should';
			}

			foreach ( $meta_query->queries as $single_meta_query ) {
				if ( empty( $single_meta_query['key'] ) ) {
					continue;
				}

				$terms_obj = array();

				$compare = '=';
				if ( ! empty( $single_meta_query['compare'] ) ) {
					$compare = strtolower( $single_meta_query['compare'] );
				}

				switch ( $compare ) {
					case '!=':
						if ( isset( $single_meta_query['value'] ) ) {
							$terms_obj = array(
								'bool' => array(
									'must_not' => array(
										array(
											'terms' => array(
												'user_meta.' . $single_meta_query['key'] . '.raw' => (array) $single_meta_query['value'],
											),
										),
									),
								),
							);
						}

						break;
					case 'exists':
						$terms_obj = array(
							'exists' => array(
								'field' => 'user_meta.' . $single_meta_query['key'],
							),
						);

						break;
					case 'not exists':
						$terms_obj = array(
							'bool' => array(
								'must_not' => array(
									array(
										'exists' => array(
											'field' => 'user_meta.' . $single_meta_query['key'],
										),
									),
								),
							),
						);

						break;
					case '>=':
						if ( isset( $single_meta_query['value'] ) ) {
							$terms_obj = array(
								'bool' => array(
									'must' => array(
										array(
											'range' => array(
												'user_meta.' . $single_meta_query['key'] . '.raw' => array(
													"gte" => $single_meta_query['value'],
												),
											),
										),
									),
								),
							);
						}

						break;
					case '<=':
						if ( isset( $single_meta_query['value'] ) ) {
							$terms_obj = array(
								'bool' => array(
									'must' => array(
										array(
											'range' => array(
												'user_meta.' . $single_meta_query['key'] . '.raw' => array(
													"lte" => $single_meta_query['value'],
												),
											),
										),
									),
								),
							);
						}

						break;
					case '>':
						if ( isset( $single_meta_query['value'] ) ) {
							$terms_obj = array(
								'bool' => array(
									'must' => array(
										array(
											'range' => array(
												'user_meta.' . $single_meta_query['key'] . '.raw' => array(
													"gt" => $single_meta_query['value'],
												),
											),
										),
									),
								),
							);
						}

						break;
					case '<':
						if ( isset( $single_meta_query['value'] ) ) {
							$terms_obj = array(
								'bool' => array(
									'must' => array(
										array(
											'range' => array(
												'user_meta.' . $single_meta_query['key'] . '.raw' => array(
													"lt" => $single_meta_query['value'],
												),
											),
										),
									),
								),
							);
						}

						break;
					case 'like':
						if ( isset( $single_meta_query['value'] ) ) {
							$terms_obj = array(
								'query' => array(
									"match" => array(
										'user_meta.' . $single_meta_query['key'] => $single_meta_query['value'],
									)
								),
							);
						}
						break;
					case '=':
					default:
						if ( isset( $single_meta_query['value'] ) ) {
							$terms_obj = array(
								'terms' => array(
									'user_meta.' . $single_meta_query['key'] . '.raw' => (array) $single_meta_query['value'],
								),
							);
						}

						break;
				}

				// Add the meta query filter
				if ( false !== $terms_obj ) {
					$meta_filter[] = $terms_obj;
				}
			}

			if ( ! empty( $meta_filter ) ) {
				$filter['and'][]['bool'][ $relation ] = $meta_filter;

				$use_filter = true;
			}
		}
		// End meta query filter

		if ( $use_filter ) {
			$ep_arguments['filter'] = $filter;
		}

		return $ep_arguments;
	}

	/**
	 * @param WP_User_Query $wp_user_query
	 */
	public function kill_query( $wp_user_query ) {
		global $wpdb;
		remove_action( 'pre_user_query', array( $this, 'kill_query' ), 99999 );
		$wp_user_query->query_fields  = "{$wpdb->users}.ID";
		$wp_user_query->query_from    = "FROM {$wpdb->users}";
		$wp_user_query->query_where   = 'WHERE 1=0';
		$wp_user_query->query_orderby = $wp_user_query->query_limit = '';
	}

	/**
	 * @return EP_User_Query_Integration|null
	 */
	public static function factory() {
		static $instance;
		if ( $instance ) {
			return $instance;
		}
		$user = ep_get_object_type( 'user' );
		if ( ! $user ) {
			return null;
		}
		if ( false === $instance ) {
			return null;
		}
		if (
			! method_exists( $user, 'active' ) ||
			! $user->active()
		) {
			$instance = false;

			return null;
		}
		$instance = new self;
		$instance->setup();

		return $instance;
	}

	/**
	 * @return bool
	 */
	private function is_user_indexing_active() {
		return (
			( ep_is_activated() || ( defined( 'WP_CLI' ) && WP_CLI ) ) &&
			method_exists( $this->user_index, 'active' ) &&
			$this->user_index->active()
		);
	}

	/**
	 * @param WP_User_Query $wp_user_query
	 *
	 * @return bool
	 */
	private function is_query_basic_enough_to_skip( $wp_user_query ) {
		$args      = $wp_user_query->query_vars;
		$safe_args = array( 'include', 'order', 'offset', 'number', 'count_total', 'fields', );
		if ( ! is_multisite() ) {
			$safe_args[] = 'blog_id';
		}
		if ( in_array( $args['orderby'], array( 'login', 'nicename', 'user_login', 'user_nicename', 'ID', 'id' ) ) ) {
			$safe_args[] = 'order';
		}
		if ( ! array_diff( array_keys( array_filter( $args ) ), $safe_args ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param WP_User_Query $wp_user_query
	 *
	 * @return bool
	 */
	private function skip_integration( $wp_user_query ) {
		return apply_filters( 'ep_skip_user_query_integration', false, $wp_user_query );
	}

	private function toggle_user_prefix( $thing, $on = null ) {
		$_thing = $thing;
		if ( 'user_' === substr( $thing, 0, 5 ) ) {
			$thing = substr( $thing, 5 );
		}
		if ( true === $on ) {
			return "user_$thing";
		} elseif ( false === $on ) {
			return $thing;
		}

		return $_thing === $thing ? "user_$_thing" : $thing;
	}

	/**
	 * @param $arguments
	 *
	 * @return array
	 */
	private function parse_sorting( $arguments ) {
		if ( empty( $arguments['order'] ) ) {
			$arguments['order'] = 'asc';
		}
		$order = strtolower( $arguments['order'] ) === 'asc' ? 'asc' : 'desc';
		if ( empty( $arguments['orderby'] ) ) {
			$orderby = array( 'user_login' => $order );
		} elseif ( is_array( $arguments['orderby'] ) ) {
			$orderby = $arguments['orderby'];
		} else {
			$orderby = preg_split( '/[,\s]+/', $arguments['orderby'] );
		}
		$sorts = array();
		foreach ( $orderby as $_key => $_value ) {
			if ( empty( $_value ) ) {
				continue;
			}
			if ( is_int( $_key ) ) {
				$_orderby = $_value;
				$_order   = $order;
			} else {
				$_orderby = $_key;
				$_order   = strtolower( $_value ) === 'asc' ? 'asc' : 'desc';
			}
			$sort_field = false;
			switch ( strtolower( $_orderby ) ) {
				case 'id':
				case 'user_id':
				case 'registered':
				case 'user_registered':
					$sort_field = array( $this->toggle_user_prefix( $_orderby, true ) => array( 'order' => $_order ) );
					break;
				case 'login':
				case 'nicename':
				case 'user_login':
				case 'user_nicename':
					$sort_field = array(
						$this->toggle_user_prefix( $_orderby, true ) . ".raw" => array( 'order' => $_order )
					);
					break;
				case 'name':
				case 'display_name':
					$sort_field = array( 'display_name.raw' => array( 'order' => $_order ) );
					break;
				case 'score':
				case 'relevance':
					$sort_field = array( '_score' => array( 'order' => $_order ) );
					break;
			}
			if ( $sort_field ) {
				$sorts[] = $sort_field;
			}
		}

		return $sorts;
	}

}

add_action( 'plugins_loaded', array( 'EP_User_Query_Integration', 'factory' ), 20 );
