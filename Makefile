SLUG      := wp-dansal
VERSION   := $(shell awk -F': ' '/^[ \t]*\*[ \t]*Version:/{gsub(/[ \t]+$$/, "", $$2); print $$2; exit}' wp-dansal.php)

BUILD_DIR := build
DIST_DIR  := dist
STAGE     := $(BUILD_DIR)/$(SLUG)
ZIP_FILE  := $(DIST_DIR)/$(SLUG)-$(VERSION).zip

# Everything that ships inside the plugin zip, as an explicit allow-list
# (rather than excluding dev cruft) so nothing new — composer.json, phpcs
# config, CI workflows, .git — leaks into a release by accident just because
# it was added to the repo root later.
DIST_FILES := wp-dansal.php includes templates assets LICENSE README.md

.PHONY: all zip build clean version help

all: zip

help:
	@echo "make zip      build $(DIST_DIR)/$(SLUG)-<version>.zip (default)"
	@echo "make build    assemble the plugin into $(STAGE)/ without zipping"
	@echo "make version  print the detected plugin version ($(VERSION))"
	@echo "make clean    remove $(BUILD_DIR)/ and $(DIST_DIR)/"

version:
	@echo "$(VERSION)"

build:
	@test -n "$(VERSION)" || { echo "Could not read Version from wp-dansal.php header" >&2; exit 1; }
	@rm -rf "$(STAGE)"
	@mkdir -p "$(STAGE)"
	@cp -R $(DIST_FILES) "$(STAGE)/"
	@find "$(BUILD_DIR)" -name '.DS_Store' -delete
	@echo "Staged plugin in $(STAGE)"

zip: build
	@mkdir -p "$(DIST_DIR)"
	@rm -f "$(ZIP_FILE)"
	@cd "$(BUILD_DIR)" && zip -rq "../$(ZIP_FILE)" "$(SLUG)"
	@echo "Built $(ZIP_FILE)"

clean:
	@rm -rf "$(BUILD_DIR)" "$(DIST_DIR)"
