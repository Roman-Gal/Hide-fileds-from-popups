		// task#49999
		getFieldsListPopupContentLinxys: function getFieldsListPopupContentLinxys() {

			var p = new BX.Promise();
			var fields = this.getParam('FIELDS');
			console.log('this', this); //test
			// ---------- helpers ----------
			var getEntityInfoFromUrl = function () {
				var path = (location.pathname || '').toLowerCase();

				// SPA
				var entityTypeId = parseInt((path.match(/\/type\/(\d+)/) || [])[1] || 0, 10);
				var categoryId   = parseInt((path.match(/\/category\/(\d+)/) || [])[1] || 0, 10);

				if (entityTypeId > 0) {
					return { entityTypeId: entityTypeId, categoryId: categoryId };
				}

				// Classic CRM
				if (path.includes('/lead/')) entityTypeId = 1;
				else if (path.includes('/deal/')) entityTypeId = 2;
				else if (path.includes('/contact/')) entityTypeId = 3;
				else if (path.includes('/company/')) entityTypeId = 4;
				else if (path.includes('/quote/')) entityTypeId = 7;

				return { entityTypeId: entityTypeId, categoryId: (categoryId || 0) };
			};

			var normalizeFieldCode = function (id) {
				id = String(id || '').trim();

				id = id.replace(/^field_/i, '');
				id = id.replace(/^FIELD_/i, '');
				id = id.replace(/^field:/i, '');

				return id.trim();
			};

			var buildPopupPayload = function (fieldsList) {
				if (!Array.isArray(fieldsList)) return [];

				return fieldsList.map(function (f) {
					var rawId = (f && (f.ID || f.Id || f.id)) ? (f.ID || f.Id || f.id) : '';
					return {
						rawId: rawId,
						normalized: normalizeFieldCode(rawId),
						label: String(
							(f && (f.LABEL || f.NAME || f.TITLE || f.CAPTION)) || ''
						).trim()
					};
				});
			};

			/**
			 * One request:
			 * - send popup fields (rawId + normalized + label)
			 * - backend loads entity editor config codes
			 * - backend compares & logs everything
			 * - backend returns ONLY intersection codes
			 */
			var loadIntersectionCodesFromServer = function (popupFieldsList) {
				var q = new BX.Promise();

				var userId = BX.message('USER_ID');
				var info = getEntityInfoFromUrl();
				var gridId = this.getParam('GRID_ID');
				var popupPayload = buildPopupPayload(popupFieldsList);
				console.log(gridId);
				BX.ajax({
					url: '/local/js/linxys.hidefieldsinsettings/ajax.php',
					method: 'POST',
					dataType: 'json',
					data: {
						action: 'get_intersection_codes',
						user_id: userId,
						entityTypeId: info.entityTypeId,
						categoryId: info.categoryId,

						filterId: this.getParam('FILTER_ID') || '',
						page: (location.pathname || ''),
						isLazy: this.getParam('LAZY_LOAD') ? 'Y' : 'N',
						popup_fields: JSON.stringify(popupPayload)
					},
					onsuccess: function (res) {

						// Admin bypass: elements=["*"]
						if (res && Array.isArray(res.elements) && res.elements.length === 1 && res.elements[0] === '*') {
							q.fulfill('*');
							return;
						}

						// Normal: intersection codes
						if (res && Array.isArray(res.codes)) {
							q.fulfill(res.codes);
							return;
						}

						// Fallback: strict hide-all
						q.fulfill([]);
					},
					onfailure: function () {
						// strict hide-all on failure (so we don't expose hidden fields)
						q.fulfill([]);
					}
				});

				return q;
			}.bind(this);

			var normalizeAllowedSet = function (allowedCodes) {
				var set = {};
				(allowedCodes || []).forEach(function (c) {
					var code = normalizeFieldCode(c);
					if (code) set[code] = true;
				});
				return set;
			};

			var filterFieldsByCodes = function (fieldsList, allowedCodes) {

				// Admin -> keep all
				if (allowedCodes === '*') return fieldsList;

				if (!Array.isArray(fieldsList)) return [];

				// STRICT: empty intersection => show nothing
				if (!Array.isArray(allowedCodes) || allowedCodes.length === 0) return [];

				var allowedSet = normalizeAllowedSet(allowedCodes);

				return fieldsList.filter(function (f) {
					var rawId = (f && (f.ID || f.Id || f.id)) ? (f.ID || f.Id || f.id) : '';
					var normId = normalizeFieldCode(rawId);
					return !!allowedSet[normId];
				});
			};

			// Final fulfill (single place)
			var fulfillWithFields = function (fieldsList) {

				var list = Array.isArray(fieldsList) ? fieldsList.slice() : [];

				var getVisibleTitle = function (f) {
					if (!f || typeof f !== 'object') return '';
					return String(
						f.LABEL ??
						f.NAME ??
						f.TITLE ??
						f.CAPTION ??
						f.ID ??
						''
					).trim();
				};

				// Sort alphabetically (German locale-friendly)
				list.sort(function (a, b) {
					return getVisibleTitle(a).localeCompare(getVisibleTitle(b), 'de', {
						sensitivity: 'base',
						numeric: true,
					});
				});

				var count = list.length;

				p.fulfill(
					this.getPopupContent(
						this.settings.classPopupFieldList,
						this.getFieldListContainerClassName(count),
						this.prepareFieldsDecl(list)
					)
				);

			}.bind(this);

			// ---------- main logic ----------
			// Case 1: LAZY_LOAD enabled -> load fields first, then compare/filter
			if (this.getParam('LAZY_LOAD')) {

				var callback = function (response) {

					var loadedFields = response; // should be array of fields

					loadIntersectionCodesFromServer(loadedFields).then(function (allowedCodes) {
						var finalFields = filterFieldsByCodes(loadedFields, allowedCodes);
						fulfillWithFields(finalFields);
					});

				}.bind(this);

				if (BX.type.isNotEmptyObject(this.getParam('LAZY_LOAD')['CONTROLLER'])) {

					var sourceComponentName = this.getParam('LAZY_LOAD')['CONTROLLER']['componentName'];
					var sourceComponentSignedParameters = this.getParam('LAZY_LOAD')['CONTROLLER']['signedParameters'];

					BX.ajax.runAction(this.getParam('LAZY_LOAD')['CONTROLLER']['getList'], {
						data: {
							filterId: this.getParam('FILTER_ID'),
							componentName: BX.type.isNotEmptyString(sourceComponentName) ? sourceComponentName : '',
							signedParameters: BX.type.isNotEmptyString(sourceComponentSignedParameters) ? sourceComponentSignedParameters : ''
						}
					}).then(function (response) {
						callback(response.data);
					}.bind(this), function () {
						// fail -> strict hide all
						fulfillWithFields([]);
					});

				} else {

					this.getLazyLoadFields().then(function (res) {
						callback(res);
					});

				}

				return p;
			}

			// Case 2: non-lazy -> compare/filter already available fields
			loadIntersectionCodesFromServer(fields).then(function (allowedCodes) {
				var finalFields = filterFieldsByCodes(fields, allowedCodes);
				fulfillWithFields(finalFields);
			});

			return p;
		},
