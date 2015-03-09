/*
 * Copyright (c) 2014
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */
(function(){

/**
 * Returns the selection of applicable users in the given configuration row
 *
 * @param $row configuration row
 * @return array array of user names
 */
function getSelection($row) {
	var values = $row.find('.applicableUsers').select2('val');
	if (!values || values.length === 0) {
		values = [];
	}
	return values;
}

function highlightBorder($element, highlight) {
	$element.toggleClass('warning-input', highlight);
	return highlight;
}

function highlightInput($input) {
	if ($input.attr('type') === 'text' || $input.attr('type') === 'password') {
		return highlightBorder($input,
			($input.val() === '' && !$input.hasClass('optional')));
	}
}

/**
 * External storage model
 */
var Storage = function(id) {
	this.id = id;
};
// Keep this in sync with \OC_Mount_Config::STATUS_*
Storage.Status = {
	IN_PROGRESS: -1,
	SUCCESS: 0,
	ERROR: 1
};
Storage.prototype = {
	_url: null,

	/**
	 * Storage id
	 *
	 * @type int
	 */
	id: null,

	/**
	 * Mount point
	 *
	 * @type string
	 */
	mountPoint: '',

	/**
	 * Backend class name
	 *
	 * @type string
	 */
	backendClass: null,

	/**
	 * Backend-specific configuration
	 *
	 * @type Object.<string,object>
	 */
	backendOptions: {},

	/**
	 * Creates or saves the storage.
	 *
	 * @param {Function} [options.success] success callback, receives result as argument
	 * @param {Function} [options.error] error callback
	 */
	save: function(options) {
		var self = this;
		var url = OC.generateUrl(this._url);
		var method = 'POST';
		if (_.isNumber(this.id)) {
			method = 'PUT';
			url = OC.generateUrl(this._url + '/{id}', {id: this.id});
		}

		$.ajax({
			type: method,
			url: url,
			data: this.getData(),
			success: function(result) {
				self.id = result.id;
				if (_.isFunction(options.success)) {
					options.success(result);
				}
			},
			error: options.error
		});
	},

	/**
	 * Returns the data from this object
	 *
	 * @return {Array} JSON array of the data
	 */
	getData: function() {
		return {
			id: this.id,
			mountPoint: this.mountPoint,
			backendClass: this.backendClass,
			backendOptions: this.backendOptions
		};
	},

	/**
	 * Recheck the storage
	 *
	 * @param {Function} [options.success] success callback, receives result as argument
	 * @param {Function} [options.error] error callback
	 */
	recheck: function(options) {
		if (!_.isNumber(this.id)) {
			if (_.isFunction(options.error)) {
				options.error();
			}
			return;
		}
		$.ajax({
			type: 'GET',
			url: OC.generateUrl(this._url + '/{id}', {id: this.id}),
			success: options.success,
			error: options.error
		});
	},

	/**
	 * Deletes the storage
	 *
	 * @param {Function} [options.success] success callback
	 * @param {Function} [options.error] error callback
	 */
	destroy: function(options) {
		if (!_.isNumber(this.id)) {
			// the storage hasn't even been created => success
			if (_.isFunction(options.success)) {
				options.success();
			}
			return;
		}
		var self = this;
		$.ajax({
			type: 'DELETE',
			url: OC.generateUrl(this._url + '/{id}', {id: this.id}),
			success: options.success,
			error: options.error
		});
	},

	/**
	 * Validate this model
	 *
	 * @return {boolean} false if errors exist, true otherwise
	 */
	validate: function() {
		if (this.mountPoint === '') {
			return false;
		}
		if (this.errors) {
			return false;
		}
		return true;
	}
};

var GlobalStorage = function(id) {
	this.id = id;
};
GlobalStorage.prototype = _.extend({}, Storage.prototype, {
	_url: 'apps/files_external/globalstorages',

	/**
	 * Applicable users
	 *
	 * @type Array.<string>
	 */
	applicableUsers: [],

	/**
	 * Applicable groups
	 *
	 * @type Array.<string>
	 */
	applicableGroups: [],

	/**
	 * Returns the data from this object
	 *
	 * @return {Array} JSON array of the data
	 */
	getData: function() {
		var data = Storage.prototype.getData.apply(this, arguments);
		return _.extend(data, {
			applicableUsers: this.applicableUsers,
			applicableGroups: this.applicableGroups,
		});
	}
});
var UserStorage = function(id) {
	this.id = id;
};
UserStorage.prototype = _.extend({}, Storage.prototype, {
	_url: 'apps/files_external/userstorages'
});

var MountConfig = {
	isPersonal: false,

	initialize: function($el) {
		this.isPersonal = ($el.data('admin') !== true);
	},

	/**
	 * Gets the storage model from the given row
	 *
	 * @param $tr row element
	 * @return {OCA.External.Storage} storage model instance
	 */
	getStorage: function($tr) {
		var storageId = parseInt($tr.attr('data-id'), 10);
		if (!storageId) {
			// new entry
			storageId = null;
		}
		var StorageClass = this.isPersonal ? OCA.External.UserStorage : OCA.External.GlobalStorage;
		var storage = new StorageClass(storageId);
		storage.mountPoint = $tr.find('.mountPoint input').val();
		storage.backendClass = $tr.find('.backend').data('class');

		var classOptions = {};
		var configuration = $tr.find('.configuration input');
		var missingOptions = [];
		$.each(configuration, function(index, input) {
			var $input = $(input);
			var parameter = $input.data('parameter');
			if ($input.attr('type') === 'button') {
				return;
			}
			if ($input.val() === '' && !$input.hasClass('optional')) {
				missingOptions.push(parameter);
				return;
			}
			if ($(input).is(':checkbox')) {
				if ($(input).is(':checked')) {
					classOptions[parameter] = true;
				} else {
					classOptions[parameter] = false;
				}
			} else {
				classOptions[parameter] = $(input).val();
			}
		});

		storage.backendOptions = classOptions;
		if (missingOptions.length) {
			storage.errors = {
				backendOptions: missingOptions
			};
		}

		// gather selected users and groups
		if (!this.isPersonal) {
			var groups = [];
			var users = [];
			var multiselect = getSelection($tr);
			$.each(multiselect, function(index, value) {
				var pos = value.indexOf('(group)');
				if (pos !== -1) {
					groups.push(value.substr(0, pos));
				} else {
					users.push(value);
				}
			});
			// FIXME: this should be done in the multiselect change event instead
			$tr.find('.applicable')
				.data('applicable-groups', groups)
				.data('applicable-users', users);

			storage.applicableUsers = users;
			storage.applicableGroups = groups;
		}

		return storage;
	},

	/**
	 * Deletes the storage from the given tr
	 *
	 * @param $tr storage row
	 * @param Function callback callback to call after save
	 */
	deleteStorage: function($tr) {
		var self = this;
		var configId = $tr.data('id');
		if (!_.isNumber(configId)) {
			// deleting unsaved storage
			$tr.remove();
			return;
		}
		var StorageClass = this.isPersonal ? OCA.External.UserStorage : OCA.External.GlobalStorage;
		var storage = new StorageClass(configId);
		this.updateStatus($tr, Storage.Status.IN_PROGRESS);

		storage.destroy({
			success: function() {
				$tr.remove();
			},
			error: function() {
				self.updateStatus($tr, Storage.Status.ERROR);
			}
		});
	},

	/**
	 * Saves the storage from the given tr
	 *
	 * @param $tr storage row
	 * @param Function callback callback to call after save
	 */
	saveStorage:function($tr, callback) {
		var self = this;
		var storage = this.getStorage($tr);
		if (!storage.validate()) {
			return false;
		}

		this.updateStatus($tr, Storage.Status.IN_PROGRESS);
		storage.save({
			success: function(result) {
				self.updateStatus($tr, result.status);
				$tr.attr('data-id', result.id);

				if (_.isFunction(callback)) {
					callback(storage);
				}
			},
			error: function() {
				self.updateStatus($tr, Storage.Status.ERROR);
			}
		});
	},

	/**
	 * Recheck storage availability
	 *
	 * @param {jQuery} $tr storage row
	 * @return {boolean} success
	 */
	recheckStorage: function($tr) {
		var self = this;
		var storage = this.getStorage($tr);
		if (!storage.validate()) {
			return false;
		}

		this.updateStatus($tr, Storage.Status.IN_PROGRESS);
		storage.recheck({
			success: function(result) {
				self.updateStatus($tr, result.status);
			},
			error: function() {
				self.updateStatus($tr, Storage.Status.ERROR);
			}
		});
	},

	/**
	 * Update status display
	 *
	 * @param {jQuery} $tr
	 * @param {int} status
	 */
	updateStatus: function($tr, status) {
		var $statusSpan = $tr.find('.status span');
		$statusSpan.removeClass('success error loading-small');
		switch (status) {
			case Storage.Status.IN_PROGRESS:
				$statusSpan.addClass('loading-small');
				break;
			case Storage.Status.SUCCESS:
				$statusSpan.addClass('success');
				break;
			default:
				$statusSpan.addClass('error');
		}
	}
};

$(document).ready(function() {
	var $externalStorage = $('#externalStorage');

	MountConfig.initialize($externalStorage);

	//initialize hidden input field with list of users and groups
	$externalStorage.find('tr:not(#addMountPoint)').each(function(i,tr) {
		var $tr = $(tr);
		var $applicable = $tr.find('.applicable');
		if ($applicable.length > 0) {
			var groups = $applicable.data('applicable-groups');
			var groupsId = [];
			$.each(groups, function () {
				groupsId.push(this + '(group)');
			});
			var users = $applicable.data('applicable-users');
			if (users.indexOf('all') > -1 || users === '') {
				$tr.find('.applicableUsers').val('');
			} else {
				$tr.find('.applicableUsers').val(groupsId.concat(users).join(','));
			}
		}
	});

	var userListLimit = 30;
	function addSelect2 ($elements) {
		if ($elements.length > 0) {
			$elements.select2({
				placeholder: t('files_external', 'All users. Type to select user or group.'),
				allowClear: true,
				multiple: true,
				//minimumInputLength: 1,
				ajax: {
					url: OC.generateUrl('apps/files_external/applicable'),
					dataType: 'json',
					quietMillis: 100,
					data: function (term, page) { // page is the one-based page number tracked by Select2
						return {
							pattern: term, //search term
							limit: userListLimit, // page size
							offset: userListLimit*(page-1) // page number starts with 0
						};
					},
					results: function (data, page) {
						if (data.status === 'success') {

							var results = [];
							var userCount = 0; // users is an object

							// add groups
							$.each(data.groups, function(i, group) {
								results.push({name:group+'(group)', displayname:group, type:'group' });
							});
							// add users
							$.each(data.users, function(id, user) {
								userCount++;
								results.push({name:id, displayname:user, type:'user' });
							});


							var more = (userCount >= userListLimit) || (data.groups.length >= userListLimit);
							return {results: results, more: more};
						} else {
							//FIXME add error handling
						}
					}
				},
				initSelection: function(element, callback) {
					var users = {};
					users['users'] = [];
					var toSplit = element.val().split(",");
					for (var i = 0; i < toSplit.length; i++) {
						users['users'].push(toSplit[i]);
					}

					$.ajax(OC.generateUrl('displaynames'), {
						type: 'POST',
						contentType: 'application/json',
						data: JSON.stringify(users),
						dataType: 'json'
					}).done(function(data) {
						var results = [];
						if (data.status === 'success') {
							$.each(data.users, function(user, displayname) {
								if (displayname !== false) {
									results.push({name:user, displayname:displayname, type:'user'});
								}
							});
							callback(results);
						} else {
							//FIXME add error handling
						}
					});
				},
				id: function(element) {
					return element.name;
				},
				formatResult: function (element) {
					var $result = $('<span><div class="avatardiv"/><span>'+escapeHTML(element.displayname)+'</span></span>');
					var $div = $result.find('.avatardiv')
						.attr('data-type', element.type)
						.attr('data-name', element.name)
						.attr('data-displayname', element.displayname);
					if (element.type === 'group') {
						var url = OC.imagePath('core','places/contacts-dark'); // TODO better group icon
						$div.html('<img width="32" height="32" src="'+url+'">');
					}
					return $result.get(0).outerHTML;
				},
				formatSelection: function (element) {
					if (element.type === 'group') {
						return '<span title="'+escapeHTML(element.name)+'" class="group">'+escapeHTML(element.displayname+' '+t('files_external', '(group)'))+'</span>';
					} else {
						return '<span title="'+escapeHTML(element.name)+'" class="user">'+escapeHTML(element.displayname)+'</span>';
					}
				},
				escapeMarkup: function (m) { return m; } // we escape the markup in formatResult and formatSelection
			}).on('select2-loaded', function() {
				$.each($('.avatardiv'), function(i, div) {
					var $div = $(div);
					if ($div.data('type') === 'user') {
						$div.avatar($div.data('name'),32);
					}
				})
			});
		}
	}
	addSelect2($('tr:not(#addMountPoint) .applicableUsers'));
	
	$externalStorage.on('change', '#selectBackend', function() {
		var $tr = $(this).closest('tr');
		$externalStorage.find('tbody').append($tr.clone());
		$externalStorage.find('tbody tr').last().find('.mountPoint input').val('');
		var selected = $(this).find('option:selected').text();
		var backendClass = $(this).val();
		$tr.find('.backend').text(selected);
		if ($tr.find('.mountPoint input').val() === '') {
			$tr.find('.mountPoint input').val(suggestMountPoint(selected));
		}
		$tr.addClass(backendClass);
		$tr.find('.status').append('<span></span>');
		$tr.find('.backend').data('class', backendClass);
		var configurations = $(this).data('configurations');
		var $td = $tr.find('td.configuration');
		$.each(configurations, function(backend, parameters) {
			if (backend === backendClass) {
				$.each(parameters['configuration'], function(parameter, placeholder) {
					var is_optional = false;
					if (placeholder.indexOf('&') === 0) {
						is_optional = true;
						placeholder = placeholder.substring(1);
					}
					var newElement;
					if (placeholder.indexOf('*') === 0) {
						var class_string = is_optional ? ' optional' : '';
						newElement = $('<input type="password" class="added' + class_string + '" data-parameter="'+parameter+'" placeholder="'+placeholder.substring(1)+'" />');
					} else if (placeholder.indexOf('!') === 0) {
						newElement = $('<label><input type="checkbox" class="added" data-parameter="'+parameter+'" />'+placeholder.substring(1)+'</label>');
					} else if (placeholder.indexOf('#') === 0) {
						newElement = $('<input type="hidden" class="added" data-parameter="'+parameter+'" />');
					} else {
						var class_string = is_optional ? ' optional' : '';
						newElement = $('<input type="text" class="added' + class_string + '" data-parameter="'+parameter+'" placeholder="'+placeholder+'" />');
					}
					highlightInput(newElement);
					$td.append(newElement);
				});
				if (parameters['custom'] && $externalStorage.find('tbody tr.'+backendClass.replace(/\\/g, '\\\\')).length === 1) {
					OC.addScript('files_external', parameters['custom']);
				}
				$td.children().not('[type=hidden]').first().focus();
				return false;
			}
		});
		$tr.find('td').last().attr('class', 'remove');
		$tr.find('td').last().removeAttr('style');
		$tr.removeAttr('id');
		$(this).remove();
		addSelect2($tr.find('.applicableUsers'));
	});

	function suggestMountPoint(defaultMountPoint) {
		var pos = defaultMountPoint.indexOf('/');
		if (pos !== -1) {
			defaultMountPoint = defaultMountPoint.substring(0, pos);
		}
		defaultMountPoint = defaultMountPoint.replace(/\s+/g, '');
		var i = 1;
		var append = '';
		var match = true;
		while (match && i < 20) {
			match = false;
			$externalStorage.find('tbody td.mountPoint input').each(function(index, mountPoint) {
				if ($(mountPoint).val() === defaultMountPoint+append) {
					match = true;
					return false;
				}
			});
			if (match) {
				append = i;
				i++;
			} else {
				break;
			}
		}
		return defaultMountPoint+append;
	}

	$externalStorage.on('paste', 'td input', function() {
		var $me = $(this);
		var $tr = $me.closest('tr');
		setTimeout(function() {
			highlightInput($me);
			MountConfig.saveStorage($tr);
		}, 20);
	});

	var timer;

	$externalStorage.on('keyup', 'td input', function() {
		clearTimeout(timer);
		var $tr = $(this).closest('tr');
		highlightInput($(this));
		if ($(this).val) {
			timer = setTimeout(function() {
				MountConfig.saveStorage($tr);
			}, 2000);
		}
	});

	$externalStorage.on('change', 'td input:checkbox', function() {
		MountConfig.saveStorage($(this).closest('tr'));
	});

	$externalStorage.on('change', '.applicable', function() {
		MountConfig.saveStorage($(this).closest('tr'));
	});

	$externalStorage.on('click', '.status>span', function() {
		MountConfig.recheckStorage($(this).closest('tr'));
	});

	$('#sslCertificate').on('click', 'td.remove>img', function() {
		var $tr = $(this).closest('tr');
		$.post(OC.filePath('files_external', 'ajax', 'removeRootCertificate.php'), {cert: $tr.attr('id')});
		$tr.remove();
		return true;
	});

	$externalStorage.on('click', 'td.remove>img', function() {
		MountConfig.deleteStorage($(this).closest('tr'));
	});

	var $allowUserMounting = $('#allowUserMounting');
	$allowUserMounting.bind('change', function() {
		OC.msg.startSaving('#userMountingMsg');
		if (this.checked) {
			OC.AppConfig.setValue('files_external', 'allow_user_mounting', 'yes');
			$('input[name="allowUserMountingBackends\\[\\]"]').prop('checked', true);
			$('#userMountingBackends').removeClass('hidden');
			$('input[name="allowUserMountingBackends\\[\\]"]').eq(0).trigger('change');
		} else {
			OC.AppConfig.setValue('files_external', 'allow_user_mounting', 'no');
			$('#userMountingBackends').addClass('hidden');
		}
		OC.msg.finishedSaving('#userMountingMsg', {status: 'success', data: {message: t('files_external', 'Saved')}});
	});

	$('input[name="allowUserMountingBackends\\[\\]"]').bind('change', function() {
		OC.msg.startSaving('#userMountingMsg');
		var userMountingBackends = $('input[name="allowUserMountingBackends\\[\\]"]:checked').map(function(){return $(this).val();}).get();
		OC.AppConfig.setValue('files_external', 'user_mounting_backends', userMountingBackends.join());
		OC.msg.finishedSaving('#userMountingMsg', {status: 'success', data: {message: t('files_external', 'Saved')}});

		// disable allowUserMounting
		if(userMountingBackends.length === 0) {
			$allowUserMounting.prop('checked', false);
			$allowUserMounting.trigger('change');

		}
	});
});

// export
OCA.External = OCA.External || {};

OCA.External.GlobalStorage = GlobalStorage;
OCA.External.UserStorage = UserStorage;
OCA.External.MountConfig = MountConfig;

})();
