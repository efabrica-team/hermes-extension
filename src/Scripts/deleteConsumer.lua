-- Redis/Dragonfly Lua script for cleaning up consumers with no pending messages
-- Usage: EVAL script 3 streamName groupName consumerName

local streamName = KEYS[1]
local groupName = KEYS[2]
local consumerName = KEYS[3]

-- Validate input parameters
if not streamName or streamName == "" then
    return {0, "Stream name is required and cannot be empty"}
end

if not groupName or groupName == "" then
    return {0, "Group name is required and cannot be empty"}
end

if not consumerName or consumerName == "" then
    return {0, "Consumer name is required and cannot be empty"}
end

-- Check pending messages for the specific consumer
-- XPENDING stream group - + count consumer
local pendingResult = redis.pcall('XPENDING', streamName, groupName, '-', '+', 1000, consumerName)

if pendingResult.err then
    return {0, "Failed to check pending messages: " .. pendingResult.err}
end

-- If consumer has pending messages, don't delete
if #pendingResult > 0 then
    return {1, "Consumer has " .. #pendingResult .. " pending messages, not deleted"}
end

-- Consumer has no pending messages, delete it
local deleteResult = redis.pcall('XGROUP', 'DELCONSUMER', streamName, groupName, consumerName)

if deleteResult.err then
    return {0, "Failed to delete consumer: " .. deleteResult.err}
end

-- deleteResult contains the number of pending messages that were deleted (should be 0)
return {1, "Consumer deleted successfully (had " .. deleteResult .. " pending messages)"}