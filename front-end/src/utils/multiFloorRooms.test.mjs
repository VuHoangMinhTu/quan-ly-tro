import assert from 'node:assert/strict'
import test from 'node:test'
import { planMultiFloorRooms } from './multiFloorRooms.mjs'
function plan(overrides = {}) {
  let n = 0
  return planMultiFloorRooms({ from: '1', to: '3', count: '3', prefix: 'P', makeKey: () => `new-${++n}`, ...overrides })
}
test('generates 3 floors and 9 correctly assigned rooms', () => {
  const result = plan()
  assert.deepEqual(result.floors.map(f => f.name), ['Tầng 1','Tầng 2','Tầng 3'])
  assert.deepEqual(result.rooms.map(r => r.room_code), ['P101','P102','P103','P201','P202','P203','P301','P302','P303'])
  assert.equal(result.rooms[3].floor_client_key, result.floors[1].client_key)
})
test('X to X appends N rooms after highest saved number without filling gaps', () => {
  const result = plan({ from: '2', to: '2', count: '2', existingRooms: [{room_code:'P201'}, {room_code:' p203 '}] })
  assert.deepEqual(result.rooms.map(r=>r.room_code), ['P204','P205'])
})
test('existing and draft reservations are house-wide and case insensitive', () => {
  const result=plan({ from: '2', to:'2', count:'2', existingRooms:[{room_code:'P202',floor_id:99}], draftRooms:[{room_code:'p205'}] })
  assert.deepEqual(result.rooms.map(r=>r.room_code), ['P206','P207'])
})
test('each floor uses its own sequence; prefix and long floor numbers do not bleed across floors', () => {
  const r=plan({from:'1',to:'2',count:'1',existingRooms:[{room_code:'P101'},{room_code:'P209'},{room_code:'P1101'},{room_code:'A199'}]})
  assert.deepEqual(r.rooms.map(x=>x.room_code),['P102','P210'])
})
test('uses existing/draft floor names and does not mutate inputs', () => {
  const draft = [{ client_key:'draft-one', name:'Tầng 1' }]
  const existing = [{id:3,name:'TẦNG 2'}]
  const result=plan({draftFloors:draft,existingFloors:existing})
  assert.equal(result.rooms[0].floor_client_key,'draft-one')
  assert.equal(result.floors[1].name,'TẦNG 2')
  assert.deepEqual(draft,[{client_key:'draft-one',name:'Tầng 1'}])
})
test('repeated generation appends after the previous preview and reuses floor key', () => {
  const a=plan({from:'1',to:'1',count:'2'})
  const b=plan({from:'1',to:'1',count:'2',draftFloors:a.floors,draftRooms:a.rooms})
  assert.deepEqual(b.rooms.map(x=>x.room_code),['P103','P104'])
  assert.equal(b.rooms[0].floor_client_key,a.floors[0].client_key)
})
test('rejects overflow, ambiguity, unsafe ranges, and invalid inputs', () => {
  for(const params of [{from:''},{from:'4'},{count:'0'},{count:'100'},{to:'100',count:'3'},{from:'1.2'},{prefix:'A'.repeat(50)},{draftRooms:Array.from({length:192},(_,i)=>({room_code:`X${i}`}))},{existingRooms:[{room_code:'P199'}]},{existingFloors:[{name:'Tầng 1'},{name:'tầng 1'}]}]) assert.throws(()=>plan(params))
  assert.equal(plan({from:'0',to:'0',count:'1',prefix:''}).rooms[0].room_code,'001')
  assert.equal(plan({from:'1',to:'200',count:'1'}).rooms.length,200)
})
