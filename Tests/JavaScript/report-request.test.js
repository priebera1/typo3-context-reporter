import { describe, expect, it } from 'vitest';
import {
  createFolderTarget,
  createRequestFromTrigger,
  createTarget,
  createTargetFromContextMenu,
} from '../../Resources/Public/JavaScript/report-request.js';

describe('createTarget', () => {
  it('maps the pages table to a page target', () => {
    expect(createTarget('pages', 7)).toEqual({ type: 'page', uid: 7 });
  });

  it('maps other tables to a record target', () => {
    expect(createTarget('tt_content', 12)).toEqual({ type: 'record', table: 'tt_content', uid: 12 });
  });

  it('maps sys_file to a file target', () => {
    expect(createTarget('sys_file', 21)).toEqual({ type: 'file', uid: 21 });
  });

  it.each([
    ['', 1],
    ['1pages', 1],
    ['tt_content;drop', 1],
    ['tt_content', 0],
    ['tt_content', -3],
    ['tt_content', Number.NaN],
    ['tt_content', 1.5],
    ['sys_file', 0],
  ])('rejects table %j with uid %j', (table, uid) => {
    expect(createTarget(table, uid)).toEqual({});
  });
});

describe('createFolderTarget', () => {
  it('accepts combined folder identifiers', () => {
    expect(createFolderTarget('1:/user_upload/Größe 2/')).toEqual({ type: 'folder', identifier: '1:/user_upload/Größe 2/' });
  });

  it.each([
    [''],
    ['/user_upload/'],
    ['0:/fileadmin/'],
    ['1:user_upload/'],
    ['1:/a\nb/'],
    [`1:/${'a'.repeat(1100)}/`],
    [42],
    [null],
  ])('rejects %j', (identifier) => {
    expect(createFolderTarget(identifier)).toEqual({});
  });
});

describe('createRequestFromTrigger', () => {
  const trigger = (dataset) => ({ dataset });

  it('uses the record of a FormEngine trigger', () => {
    expect(createRequestFromTrigger(trigger({
      contextReporterTrigger: 'formEngine',
      contextReporterTable: 'tt_content',
      contextReporterUid: '10',
    }))).toEqual({ source: 'formEngine', target: { type: 'record', table: 'tt_content', uid: 10 } });
  });

  it.each([
    ['recordList', { contextReporterTable: 'tt_content', contextReporterUid: '5' }, { type: 'record', table: 'tt_content', uid: 5 }],
    ['pageModule', { contextReporterTable: 'pages', contextReporterUid: '8' }, { type: 'page', uid: 8 }],
    ['fileList', { contextReporterTable: 'sys_file', contextReporterUid: '3' }, { type: 'file', uid: 3 }],
    ['fileList', { contextReporterFolder: '2:/Bilder/' }, { type: 'folder', identifier: '2:/Bilder/' }],
  ])('supports the %s entry point', (source, dataset, target) => {
    expect(createRequestFromTrigger(trigger({ contextReporterTrigger: source, ...dataset }))).toEqual({ source, target });
  });

  it('falls back to the toolbar source without a target', () => {
    expect(createRequestFromTrigger(trigger({ contextReporterTrigger: 'somewhere' }))).toEqual({ source: 'toolbar', target: {} });
  });

  it('ignores a partial uid', () => {
    expect(createRequestFromTrigger(trigger({
      contextReporterTrigger: 'contextMenu',
      contextReporterTable: 'pages',
      contextReporterUid: '12abc',
    }))).toEqual({ source: 'contextMenu', target: {} });
  });
});

describe('createTargetFromContextMenu', () => {
  it('uses table and uid of pages and records', () => {
    expect(createTargetFromContextMenu('tt_content', '12', {})).toEqual({ type: 'record', table: 'tt_content', uid: 12 });
    expect(createTargetFromContextMenu('pages', 3)).toEqual({ type: 'page', uid: 3 });
  });

  it('uses the file uid provided by the item for files', () => {
    expect(createTargetFromContextMenu('sys_file', '1:/user_upload/logo.png', { contextReporterTarget: 'file', contextReporterUid: '21' }))
      .toEqual({ type: 'file', uid: 21 });
  });

  it('uses the combined identifier for folders and storages', () => {
    expect(createTargetFromContextMenu('sys_file', '1:/user_upload/', { contextReporterTarget: 'folder' }))
      .toEqual({ type: 'folder', identifier: '1:/user_upload/' });
    expect(createTargetFromContextMenu('sys_file_storage', '1:/', { contextReporterTarget: 'folder' }))
      .toEqual({ type: 'folder', identifier: '1:/' });
  });

  it('does not treat file identifiers as record uids', () => {
    expect(createTargetFromContextMenu('sys_file', '1:/user_upload/logo.png', {})).toEqual({});
  });
});
