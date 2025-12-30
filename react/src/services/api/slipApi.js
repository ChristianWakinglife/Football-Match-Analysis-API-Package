import axios from "axios";

const API_URL = import.meta.env.VITE_API_URL || "http://localhost:8000/api";

const slipApi = {
  // Get master slips (user's slips)
  getMasterSlips: async () => {
    try {
      const response = await axios.get(`${API_URL}/all-master-slips`);
      // Ensure we always return an array
      return Array.isArray(response.data)
        ? response.data
        : response.data.data
          ? response.data.data
          : response.data.slips
            ? response.data.slips
            : response.data.results
              ? response.data.results
              : [];
    } catch (error) {
      console.error("Error fetching master slips:", error);
      throw error;
    }
  },

  // Get single master slip details
  getMasterSlip: async (id) => {
    try {
      const response = await axios.get(`${API_URL}/da-master-slips/${id}`);
      return response.data;
    } catch (error) {
      console.error("Error fetching master slip:", error);
      throw error;
    }
  },

  // Create a new slip
  createSlip: async (slipData) => {
    try {
      const response = await axios.post(`${API_URL}/slips/create`, slipData);
      return response.data;
    } catch (error) {
      console.error("Error creating slip:", error);
      throw error;
    }
  },

  // getAvailableMatches
  getAvailableMatches: async () => {
    try {
      const response = await axios.get(`${API_URL}/matches`);
      return response.data;
    } catch (error) {
      console.error("Error fetching matches:", error);
      throw error;
    }
  },

  // Update a slip
  updateSlip: async (id, slipData) => {
    try {
      const response = await axios.put(
        `${API_URL}/single-slips/${id}`,
        slipData
      );
      return response.data;
    } catch (error) {
      console.error("Error updating slip:", error);
      throw error;
    }
  },

  // Delete a slip
  deleteSlip: async (id) => {
    try {
      const response = await axios.delete(`${API_URL}/slips/${id}`);
      return response.data;
    } catch (error) {
      console.error("Error deleting slip:", error);
      throw error;
    }
  },

  // Get all matches from matches table
  getAllMatches: async (params = {}) => {
    try {
      const response = await axios.get(`${API_URL}/matches`);
      return response.data;
    } catch (error) {
      console.error("Error fetching matches:", error);
      throw error;
    }
  },

  // Get slip matches for a specific slip
  getSlipMatches: async (slipId) => {
    try {
      const response = await axios.get(`${API_URL}/slips/${slipId}/matches`);
      return response.data;
    } catch (error) {
      console.error("Error fetching slip matches:", error);
      throw error;
    }
  },

  // Get generated slips for a master slip
  getGeneratedSlips: async (slipId) => {
    try {
      const response = await axios.get(`${API_URL}/slips/${slipId}/generated`);
      return response.data;
    } catch (error) {
      console.error("Error fetching generated slips:", error);
      throw error;
    }
  },

  // Add a match to slip

  // Remove a match from slip
  removeMatchFromSlip: async (slipId, matchId) => {
    try {
      const response = await axios.delete(
        `${API_URL}/slips/${slipId}/remove-match/${matchId}`
      );
      return response.data;
    } catch (error) {
      console.error("Error removing match from slip:", error);
      throw error;
    }
  },
  getSlipInsights: async (slipId) => {
    try {
      const response = await axios.get(`${API_URL}/slips/${slipId}/insights`);
      return response.data;
    } catch (error) {
      console.error("Error fetching slip insights:", error);
      throw error;
    }
  },

  runSlipAnalysis: async (slipId) => {
    try {
      const response = await axios.post(`${API_URL}/slips/${slipId}/analyze`);
      return response.data;
    } catch (error) {
      console.error("Error running slip analysis:", error);
      throw error;
    }
  },

  checkAnalysisStatus: async (slipId) => {
    try {
      const response = await axios.get(`${API_URL}/slips/${slipId}/status`);
      return response.data;
    } catch (error) {
      console.error("Error checking analysis status:", error);
      throw error;
    }
  },

  // FILTER FUNCTION - Add this new function
  filterSlips: (slips, criteria) => {
    // Make sure slips is an array
    if (!Array.isArray(slips)) {
      console.warn("filterSlips called with non-array:", slips);
      return [];
    }

    return slips.filter((slip) => {
      // Filter by tab criteria
      if (criteria.activeTab === 1) {
        // High Confidence tab
        return slip.confidence_score > 75;
      }

      // Add more filter criteria as needed
      if (criteria.status) {
        return slip.status === criteria.status;
      }

      // All tab - return all slips
      return true;
    });
  },

  // Additional utility function for sorting
  sortSlips: (slips, sortBy = "created_at", order = "desc") => {
    if (!Array.isArray(slips)) {
      console.warn("sortSlips called with non-array:", slips);
      return [];
    }

    return [...slips].sort((a, b) => {
      let valA = a[sortBy];
      let valB = b[sortBy];

      // Handle dates
      if (sortBy.includes("date") || sortBy.includes("at")) {
        valA = new Date(valA).getTime();
        valB = new Date(valB).getTime();
      }

      if (valA < valB) return order === "asc" ? -1 : 1;
      if (valA > valB) return order === "asc" ? 1 : -1;
      return 0;
    });
  },

  getSlipForDashboard: async (slipId) => {
    try {
      const response = await axios.get(`${API_URL}/slips/${slipId}/dashboard`);
      return response.data;
    } catch (error) {
      console.error("Error fetching slip for dashboard:", error);
      throw error;
    }
  },

  // Add matches to slip
  addMatchesToSlip: async (slipId, slipData) => {
    try {
      const response = await axios.post(
        `${API_URL}/slips/${slipId}/add-match`,
        slipData
      );
      return response.data;
    } catch (error) {
      console.error("Error adding matches to slip:", error);
      throw error;
    }
  },

  getMasterSlipAnalysis: async (slipId) => {
    try {
      const response = await axios.get(`${API_URL}/slips/${slipId}/analysis`);
      return response.data;
    } catch (error) {
      console.error("Error fetching slip analysis:", error);
      throw error;
    }
  },
  getGeneratedSlips: async (masterslipid) => {
    try {
      const response = await axios.get(
        `${API_URL}/master-slips/${masterslipid}/generated-slips`
      );
      return response.data;
    } catch (error) {
      console.error("Error fetching slip analysis:", error);
      throw error;
    }
  },

  deleteAllGeneratedSlips: (masterSlipId) => {
    return axios.delete(
      `${API_URL}/master-slips/${masterSlipId}/generated-slips`
    );
  },
};




export default slipApi;





